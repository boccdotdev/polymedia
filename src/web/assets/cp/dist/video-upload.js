(function () {
  'use strict';
  if (typeof Craft === 'undefined') return;

  /**
   * Starts a cancellable hosted video upload.
   *
   * Preflight authorizes the destination before any bytes leave the browser.
   * The signed context it returns binds the provider upload's creation,
   * status polling and completion. Cancelling stops the transfer; it never
   * deletes a video the provider already created.
   *
   * @param {File} file the video file
   * @param {Object} context destination context (folderId, fieldId, elementId, siteId, title)
   * @param {function(number)} progress called with the transfer percentage
   * @returns {{promise: Promise<Object>, abort: function()}}
   */
  Craft.PolymediaVideoUpload = function (file, context, progress) {
    var cancelled = false;
    var transfer;
    var timer;
    var rejectWait;
    var request = function (action, data) {
      if (cancelled) return Promise.reject(new Error('Upload cancelled.'));
      return Craft.sendActionRequest('POST', action, {data: data}).then(function (response) {
        if (cancelled) throw new Error('Upload cancelled.');
        return response.data;
      });
    };
    var wait = function () {
      return new Promise(function (resolve, reject) {
        rejectWait = reject;
        timer = setTimeout(resolve, 2000);
      });
    };
    var promise = request('polymedia/video-uploads/preflight',
      Object.assign({}, context, {filename: file.name})
    ).then(async function (auth) {
      if (auth.route === false && context.routing) return {native: true};
      if (!auth.token || !auth.folderId || !['mux', 'bunny'].includes(auth.provider)) {
        throw new Error('Invalid upload authorization.');
      }
      var action = function (name) { return 'polymedia/' + auth.provider + '/' + name; };
      var params = {folderId: auth.folderId, uploadContext: auth.token, title: context.title || file.name};
      var upload = await request(action('create-upload'), params);
      if (!upload.uploadId || !upload.uploadUrl) throw new Error('Invalid upload response.');
      await new Promise(function (resolve, reject) {
        rejectWait = reject;
        if (auth.provider === 'bunny') {
          if (typeof tus === 'undefined') return reject(new Error('Video uploader is unavailable.'));
          transfer = new tus.Upload(file, {
            endpoint: upload.uploadUrl,
            retryDelays: [0, 1000, 3000, 5000],
            storeFingerprintForResuming: false,
            headers: upload.headers || {},
            metadata: {filetype: file.type, title: params.title},
            onProgress: function (sent, total) { progress(total ? sent / total * 100 : 0); },
            onError: reject,
            onSuccess: resolve,
          });
          transfer.start();
        } else {
          if (typeof UpChunk === 'undefined') return reject(new Error('Video uploader is unavailable.'));
          transfer = UpChunk.createUpload({endpoint: upload.uploadUrl, file: file, chunkSize: 5120});
          transfer.on('progress', function (event) {
            progress(typeof event.detail === 'number' ? event.detail : event.detail.progress);
          });
          transfer.on('error', function (event) {
            reject(new Error((event.detail && event.detail.message) || 'Upload failed.'));
          });
          transfer.on('success', resolve);
        }
      });
      for (var attempt = 0; attempt < 90; attempt++) {
        var status;
        try {
          status = await request(action('upload-status'), {
            uploadId: upload.uploadId, uploadContext: auth.token,
          });
        } catch (error) {
          var code = error.response && error.response.status;
          if (cancelled || (code && code < 500 && code !== 429)) throw error;
          await wait();
          continue;
        }
        if (status.failed) throw new Error(status.message || 'Video processing failed.');
        if (status.ready) {
          return request(action('complete-upload'), Object.assign({}, params, {
            uploadId: upload.uploadId,
          }));
        }
        await wait();
      }
      throw new Error('Video processing timed out. Check the video library before uploading again.');
    });
    return {
      promise: promise,
      abort: function () {
        cancelled = true;
        clearTimeout(timer);
        if (transfer && transfer.abort) {
          var aborted = transfer.abort();
          if (aborted && aborted.catch) aborted.catch(function () {});
        }
        if (rejectWait) rejectWait(new Error('Upload cancelled.'));
      },
    };
  };
})();
