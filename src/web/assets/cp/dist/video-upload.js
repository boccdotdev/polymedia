(function () {
  'use strict';
  if (typeof Craft === 'undefined') return;

  // A cancellable transfer shared by the explicit modal and native AJAX transport.
  // Authorization and destination resolution always happen before provider bytes.
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
            headers: upload.headers || {
              AuthorizationSignature: upload.signature,
              AuthorizationExpire: String(upload.expirationTime),
              LibraryId: String(upload.libraryId),
              VideoId: upload.videoId,
            },
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
            muxAssetId: auth.provider === 'mux' ? status.assetId : undefined,
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
