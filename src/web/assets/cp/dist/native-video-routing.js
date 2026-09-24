(function () {
  'use strict';
  var config = window.CraftPolymediaConfig || {};
  if (!config.isPro || !config.videoEnabled || !config.autoRouteVideoUploads ||
      !config.videoUploadVolumeUids || !config.videoUploadVolumeUids.length ||
      typeof Craft === 'undefined' || !Craft.Uploader || !$.ajaxTransport) return;

  // Refuse a conflicting custom uploader before files can reach native storage.
  // The server supplies filesystem types used by the selected routing volumes.
  // We cannot safely split arbitrary custom uploaders, so their native controls
  // remain disabled until that invalid routing configuration is removed.
  var blockCustomUploader = function (fsType, container, settings) {
    settings = settings || {};
    var input = settings.fileInput;
    var button = container && container.is && container.is('button')
      ? container : (input && input.prev ? input.prev('button') : null);
    if (input && input.prop) input.prop('disabled', true);
    if (button && button.prop) button.prop('disabled', true).addClass('disabled');
    var notify = Craft.cp.displayError || Craft.cp.displayNotice;
    notify.call(Craft.cp, Craft.t('polymedia',
      'Native uploads are disabled for this custom filesystem because it was selected for automatic video routing. Remove its volumes from automatic routing, or use Upload video.'));
    var preventDrop = function (event) {
      event.preventDefault();
      event.stopPropagation();
    };
    if (settings.dropZone && settings.dropZone.on) {
      settings.dropZone.on('dragover drop', preventDrop);
    }
    return {
      fsType: fsType,
      settings: settings,
      setParams: function () {},
      getInProgress: function () { return 0; },
      isLastUpload: function () { return true; },
      destroy: function () {
        if (settings.dropZone && settings.dropZone.off) settings.dropZone.off('dragover drop', preventDrop);
        if (input && input.prop) input.prop('disabled', false);
        if (button && button.prop) button.prop('disabled', false).removeClass('disabled');
      },
    };
  };

  if (Craft.createUploader) {
    var createUploader = Craft.createUploader;
    var warned = false;
    Craft.createUploader = function (fsType, container, settings) {
      var protectedType = (config.videoUploadFsTypes || []).includes(fsType);
      var replacing = settings && settings.replace;
      var registered = Craft._uploaderClasses && Craft._uploaderClasses[fsType];
      if (protectedType && !replacing && registered && registered !== Craft.Uploader) {
        return blockCustomUploader(fsType, container, settings);
      }
      var uploader = createUploader.apply(this, arguments);
      if (uploader && Object.getPrototypeOf(uploader) !== Craft.Uploader.prototype && protectedType && !replacing) {
        if (uploader.destroy) uploader.destroy();
        return blockCustomUploader(fsType, container, settings);
      }
      if (uploader && Object.getPrototypeOf(uploader) !== Craft.Uploader.prototype && !warned && !replacing) {
        warned = true;
        Craft.cp.displayNotice(Craft.t('polymedia',
          'Automatic video routing is unavailable for this filesystem. Use Upload video to send videos to the video library.'));
      }
      return uploader;
    };
  }

  var original = Craft.Uploader.prototype.onFileAdd;
  Craft.Uploader.prototype.onFileAdd = function (event, data) {
    var params = Object.assign({}, this.formData);
    var index = this.$element && this.$element.data('polymediaIndex');
    if (index && Craft.Polymedia) {
      params = Object.assign(params, Craft.Polymedia.uploadContext(index));
    }
    if (Object.getPrototypeOf(this) === Craft.Uploader.prototype &&
        !this.settings.replace && !params.assetId && !params.replace &&
        data.files && data.files.length === 1 && /\.mp4$/i.test(data.files[0].name)) {
      var kinds = this.allowedKinds;
      var originalKindAllowed = !kinds || kinds.some(function (kind) {
        var extensions = Craft.fileKinds && Craft.fileKinds[kind] &&
          Craft.fileKinds[kind].extensions;
        return extensions && extensions.some(function (extension) {
          return extension.toLowerCase() === 'mp4';
        });
      });
      data.polymediaRoute = {
        file: data.files[0],
        context: Object.assign(params, {routing: true}),
        originalKindAllowed: originalKindAllowed,
      };
      // Disable native chunking for this one request: the provider owns chunks.
      data.maxChunkSize = 0;
      // Craft snapshots validateExtension synchronously before data.process().
      // The server validates the resulting pmedia kind instead. Restore the
      // shared uploader immediately so ordinary files retain native validation.
      this.allowedKinds = null;
      try {
        return original.call(this, event, data);
      } finally {
        this.allowedKinds = kinds;
      }
    }
    // Native validation, rejection messages, limits, queue and active counters
    // still own submission. No remote upload starts before data.submit().
    return original.call(this, event, data);
  };

  $.ajaxTransport('+*', function (options) {
    if (!options.polymediaRoute) return;
    var task;
    var nativeRequest;
    var cancelled = false;
    return {
      send: function (headers, complete) {
        var route = options.polymediaRoute;
        task = Craft.PolymediaVideoUpload(route.file, route.context, function (percent) {
          // Blueimp installs its progress listener on this cached, unsent XHR.
          // Dispatch the same public browser event instead of calling internals.
          var xhr = options.xhr && options.xhr();
          if (xhr && xhr.upload && typeof ProgressEvent !== 'undefined') {
            xhr.upload.dispatchEvent(new ProgressEvent('progress', {
              lengthComputable: true,
              loaded: Math.round(route.file.size * percent / 100),
              total: route.file.size,
            }));
          }
        });
        task.promise.then(function (result) {
          if (cancelled) return;
          if (result.native) {
            if (!route.originalKindAllowed) {
              throw new Error('This field does not allow native MP4 files in this destination.');
            }
            var nativeOptions = Object.assign({}, options, {headers: headers});
            delete nativeOptions.polymediaRoute;
            delete nativeOptions.success;
            delete nativeOptions.error;
            delete nativeOptions.complete;
            delete nativeOptions.statusCode;
            delete nativeOptions.beforeSend;
            // This is an explicit server decision for a non-allowlisted volume,
            // never recovery from an authorization or provider failure.
            nativeRequest = $.ajax(nativeOptions);
            nativeRequest.done(function (data) {
              complete(200, 'success', {json: data});
            }).fail(function (xhr) {
              complete(xhr.status || 500, 'error', {text: xhr.responseText});
            });
          } else {
            complete(200, 'success', {json: result});
          }
        }).catch(function (error) {
          if (cancelled) return;
          if (task) task.abort();
          var message = (error.response && error.response.data && error.response.data.message) ||
            error.message || 'Video upload failed.';
          complete(400, 'error', {text: JSON.stringify({message: message})});
        });
      },
      abort: function () {
        cancelled = true;
        if (task) task.abort();
        if (nativeRequest) nativeRequest.abort();
      },
    };
  });
})();
