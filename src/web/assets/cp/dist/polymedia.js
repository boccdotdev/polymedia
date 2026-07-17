(function () {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  // Asset select input that pins inline uploads to a fixed folder, so a poster
  // image or caption file uploaded straight from the field lands in the same
  // place as the .pmedia file instead of erroring for want of a target folder
  // (the stock AssetSelectInput resolves the folder from a field id we lack here).
  if (Craft.AssetSelectInput) {
    Craft.PolymediaPosterInput = Craft.AssetSelectInput.extend({
      _attachUploader: function () {
        this.base();

        if (this.uploader && this.settings.folderId) {
          this.uploader.setParams({ folderId: this.settings.folderId });
        }
      },
    });
  }

  Craft.Polymedia = {
    // Set from PHP via window.CraftPolymediaConfig (Pro + Mux credentials).
    muxEnabled: !!(
      window.CraftPolymediaConfig && window.CraftPolymediaConfig.muxEnabled
    ),
    isPro: !!(window.CraftPolymediaConfig && window.CraftPolymediaConfig.isPro),

    init: function () {
      // Catches indexes created after this runs, e.g. asset selection modals.
      Garnish.on(Craft.AssetIndex, 'afterInit', function (ev) {
        Craft.Polymedia.injectButton(ev.target);
      });

      // The standalone Assets index initializes during page load and fires
      // `afterInit` before the listener above is bound, so catch it directly.
      if (Craft.elementIndex instanceof Craft.AssetIndex) {
        Craft.Polymedia.injectButton(Craft.elementIndex);
      }
    },

    injectButton: function (assetIndex) {
      if (!assetIndex || assetIndex._polymediaBtnInjected) {
        return;
      }

      // Single disclosure: Add media → From URL / Mux browse / Mux upload.
      // Craft’s “Upload files” stays separate (volume file upload).
      // Items are built via Garnish.DisclosureMenu#addItem so activate handlers
      // are wired the same way as native CP menus (delegated activate fails).
      var menuId =
        'polymedia-add-media-' + Math.floor(Math.random() * 1000000);

      var $btn = $(
        '<button type="button" class="btn menubtn add icon polymedia-add-media-btn" ' +
          'data-disclosure-trigger aria-controls="' +
          menuId +
          '" aria-haspopup="true">' +
          Craft.escapeHtml(Craft.t('polymedia', 'Add media')) +
          '</button>'
      );

      var $menu = $(
        '<div id="' +
          menuId +
          '" class="menu menu--disclosure"><ul></ul></div>'
      );

      var place = function () {
        if (assetIndex.settings && assetIndex.settings.context === 'index') {
          // Assets index: immediately before Craft’s Upload files button.
          var $upload = assetIndex.$uploadButton;

          if ($upload && $upload.length) {
            $upload.before($btn);
          } else if (!$btn.parent().length) {
            assetIndex.addButton($btn);
          }
        } else {
          // Field selection modal toolbar.
          var $toolbar = assetIndex.$toolbar;

          if (!$toolbar || !$toolbar.length) {
            return;
          }

          if (!$btn.parent().length) {
            var $uploadBtn = $toolbar.find('.btn[data-action="upload"]');

            if (!$uploadBtn.length) {
              $uploadBtn = $toolbar.find('.btn.submit').first();
            }

            if ($uploadBtn.length) {
              $uploadBtn.before($btn);
            } else {
              $toolbar.append($btn);
            }
          }
        }

        // Menu lives on body once DisclosureMenu inits; keep a sibling for first init.
        if (!$menu.data('disclosureMenu') && !$menu.parent().length) {
          $btn.after($menu);
          $btn.disclosureMenu();
          Craft.Polymedia._populateAddMediaMenu($menu, assetIndex);
        }
      };

      place();

      if (assetIndex.settings && assetIndex.settings.context === 'index') {
        // Craft rebuilds the upload button on source change — re-place ours.
        assetIndex.on('selectSource', place);
      }

      assetIndex._polymediaBtnInjected = true;
    },

    /**
     * Fills the Add media disclosure with native DisclosureMenu items.
     *
     * @param {jQuery} $menu
     * @param {Craft.AssetIndex} assetIndex
     */
    _populateAddMediaMenu: function ($menu, assetIndex) {
      var disclosure = $menu.data('disclosureMenu');

      if (!disclosure || typeof disclosure.addItem !== 'function') {
        return;
      }

      // Empty the placeholder list before adding wired items.
      $menu.children('ul').empty();

      disclosure.addItem({
        label: Craft.t('polymedia', 'From URL'),
        description: Craft.t(
          'polymedia',
          'Paste a YouTube, Vimeo, Mux, HLS, or other media URL'
        ),
        onActivate: function () {
          Craft.Polymedia.openSlideout(assetIndex);
        },
      });

      if (!Craft.Polymedia.muxEnabled) {
        return;
      }

      disclosure.addItem({
        label: Craft.t('polymedia', 'Browse Mux library'),
        description: Craft.t(
          'polymedia',
          'Import a video already in your Mux account'
        ),
        onActivate: function () {
          Craft.Polymedia.openMuxBrowse(assetIndex);
        },
      });

      disclosure.addItem({
        label: Craft.t('polymedia', 'Upload to Mux'),
        description: Craft.t(
          'polymedia',
          'Upload a video file directly to Mux'
        ),
        onActivate: function () {
          Craft.Polymedia.openMuxUpload(assetIndex);
        },
      });
    },

    openSlideout: function (assetIndex) {
      var params = {};
      var folderId = Craft.Polymedia._folderId(assetIndex);

      if (folderId) {
        params.folderId = folderId;
      }

      var slideout = new Craft.CpScreenSlideout(
        'polymedia/media-items/create-screen',
        { params: params }
      );

      slideout.on('submit', function () {
        Craft.cp.displayNotice(Craft.t('polymedia', 'Media item created.'));
        Craft.Polymedia._refreshIndex(assetIndex);
      });
    },

    openMuxBrowse: function (assetIndex) {
      if (!Craft.Polymedia.muxEnabled) {
        return;
      }

      new Craft.Polymedia.MuxBrowseModal({
        assetIndex: assetIndex,
        folderId: Craft.Polymedia._folderId(assetIndex),
      });
    },

    openMuxUpload: function (assetIndex) {
      if (!Craft.Polymedia.muxEnabled) {
        return;
      }

      new Craft.Polymedia.MuxUploadModal({
        assetIndex: assetIndex,
        folderId: Craft.Polymedia._folderId(assetIndex),
      });
    },

    _folderId: function (assetIndex) {
      if (!assetIndex) {
        return null;
      }

      var folderId = assetIndex.currentFolderId;

      if (!folderId && assetIndex.$source) {
        folderId = assetIndex.$source.data('folder-id');
      }

      return folderId || null;
    },

    _refreshIndex: function (assetIndex) {
      if (assetIndex) {
        assetIndex.updateElements();
      } else if (Craft.elementIndex) {
        Craft.elementIndex.updateElements();
      }
    },
  };

  /**
   * Modal: live Mux library grid with import / reuse.
   */
  Craft.Polymedia.MuxBrowseModal = Garnish.Modal.extend({
    assetIndex: null,
    folderId: null,
    page: 1,
    limit: 24,
    loading: false,
    $body: null,
    $grid: null,
    $status: null,
    $pager: null,

    init: function (settings) {
      this.assetIndex = settings.assetIndex || null;
      this.folderId = settings.folderId || null;

      // Not “fitted”: empty/loading states need a stable wide shell (CSS min-width).
      var $container = $(
        '<div class="modal polymedia-mux-modal" role="dialog" aria-label="' +
          Craft.escapeHtml(Craft.t('polymedia', 'Browse Mux library')) +
          '"/>'
      );

      var $header = $(
        '<div class="header">' +
          '<h1>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Browse Mux library')) +
          '</h1>' +
          '</div>'
      );

      this.$body = $('<div class="body"/>');
      this.$status = $('<div class="polymedia-mux-status"/>').appendTo(this.$body);
      this.$grid = $('<div class="polymedia-mux-grid"/>').appendTo(this.$body);
      this.$pager = $('<div class="polymedia-mux-pager"/>').appendTo(this.$body);

      var $footer = $(
        '<div class="footer">' +
          '<div class="buttons right">' +
          '<button type="button" class="btn" data-action="close">' +
          Craft.escapeHtml(Craft.t('polymedia', 'Close')) +
          '</button>' +
          '</div>' +
          '</div>'
      );

      $container.append($header, this.$body, $footer);

      this.base($container, {
        autoShow: false,
        hideOnShadeClick: true,
        shadeClass: 'modal-shade dark',
      });

      // Garnish locks measured width/height as min-*; set desiredWidth so empty
      // library states stay usable (CSS alone is overwritten on updateSizeAndPosition).
      this.desiredWidth = Math.min(960, Math.max(560, Garnish.$win.width() - 48));
      this.updateSizeAndPosition = function () {
        Garnish.Modal.prototype.updateSizeAndPosition.call(this);
        this._fitHeight();
      };
      this.show();

      this.addListener($footer.find('[data-action="close"]'), 'click', 'hide');
      this.loadPage(1);
    },

    /**
     * Re-measure height to content after async updates (empty/error/grid load).
     * Garnish otherwise keeps a stale min-height from the first layout pass.
     */
    _fitHeight: function () {
      if (!this.$container || !this.$container.length) {
        return;
      }

      var width = this.desiredWidth || Math.min(960, Math.max(560, Garnish.$win.width() - 48));
      // Release the body’s previously-fitted height so the shell measures to its
      // natural content height (a leftover value would skew the measurement).
      this.$body.css({ height: '', overflowY: '' });
      // Measure with height:auto so footer padding isn’t clipped by border-box.
      this.$container.css({
        width: width,
        minWidth: width,
        height: 'auto',
        minHeight: 0,
      });
      var height = Math.min(
        this.$container.outerHeight(),
        Garnish.$win.height() - 2 * (this.settings.minGutter || 10)
      );
      height = Math.max(height, 200);
      this.$container.css({
        height: height,
        minHeight: height,
        left: Math.round((Garnish.$win.width() - width) / 2),
        top: Math.round((Garnish.$win.height() - height) / 2),
      });

      // Garnish fades the modal in with an inline `display: block`, which beats
      // the stylesheet’s `display: flex` — so the body can’t rely on flex to fill
      // the shell. Bound its height to the space between header and footer so it
      // scrolls instead of overflowing the fixed-height, clipped container.
      var chrome =
        this.$container.children('.header').outerHeight() +
        this.$container.children('.footer').outerHeight();
      this.$body.css({ height: Math.max(height - chrome, 0), overflowY: 'auto' });
    },

    loadPage: function (page) {
      var self = this;

      if (this.loading) {
        return;
      }

      this.loading = true;
      this.page = page;
      this.$status.text(Craft.t('polymedia', 'Loading Mux library…'));
      this.$grid.empty();
      this.$pager.empty();

      // GET query args go in `params`; `data` becomes a request body that the
      // server ignores, so page/limit would silently fall back to defaults.
      Craft.sendActionRequest('GET', 'polymedia/mux/library', {
        params: { page: page, limit: this.limit },
      })
        .then(function (response) {
          self.loading = false;
          var data = response.data || {};
          var items = data.items || [];

          self.$status.empty();

          if (!items.length) {
            self.$status.text(Craft.t('polymedia', 'No Mux assets found.'));
            self.updateSizeAndPosition();
            return;
          }

          items.forEach(function (item) {
            self.$grid.append(self._card(item));
          });

          self._renderPager(data.page || page, items.length);
          self.updateSizeAndPosition();
        })
        .catch(function (error) {
          self.loading = false;
          var message =
            (error &&
              error.response &&
              error.response.data &&
              error.response.data.message) ||
            Craft.t('polymedia', 'Could not load Mux library.');
          self.$status.text(message);
          Craft.cp.displayError(message);
          self.updateSizeAndPosition();
        });
    },

    _renderPager: function (page, count) {
      var self = this;
      this.$pager.empty();

      if (page <= 1 && count < this.limit) {
        return;
      }

      var $prev = $(
        '<button type="button" class="btn small"' +
          (page <= 1 ? ' disabled' : '') +
          '>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Previous')) +
          '</button>'
      );
      var $next = $(
        '<button type="button" class="btn small"' +
          (count < this.limit ? ' disabled' : '') +
          '>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Next')) +
          '</button>'
      );

      if (page > 1) {
        $prev.on('click', function () {
          self.loadPage(page - 1);
        });
      }

      if (count >= this.limit) {
        $next.on('click', function () {
          self.loadPage(page + 1);
        });
      }

      this.$pager.append($prev, $('<span class="page">').text(String(page)), $next);
    },

    _card: function (item) {
      var self = this;
      var title =
        item.title ||
        item.playbackId ||
        item.assetId ||
        Craft.t('polymedia', 'Untitled');
      var status = item.status || '';
      var thumb = item.thumbnailUrl
        ? '<img src="' +
          Craft.escapeHtml(item.thumbnailUrl) +
          '" alt="" loading="lazy"/>'
        : '<div class="polymedia-mux-thumb-placeholder"/>';

      var badges = '';

      if (item.alreadyImported) {
        badges +=
          '<span class="polymedia-mux-badge in-craft">' +
          Craft.escapeHtml(Craft.t('polymedia', 'In Craft')) +
          '</span>';
      }

      if (item.isPublic === false) {
        badges +=
          '<span class="polymedia-mux-badge signed">' +
          Craft.escapeHtml(Craft.t('polymedia', 'Signed')) +
          '</span>';
      }

      if (status && status !== 'ready') {
        var statusLabel =
          status === 'preparing'
            ? Craft.t('polymedia', 'Processing')
            : status === 'errored'
              ? Craft.t('polymedia', 'Errored')
              : status;
        badges +=
          '<span class="polymedia-mux-badge status">' +
          Craft.escapeHtml(statusLabel) +
          '</span>';
      }

      var actionLabel = item.alreadyImported
        ? Craft.t('polymedia', 'In Craft')
        : Craft.t('polymedia', 'Import');

      var $card = $(
        '<div class="polymedia-mux-card' +
          (item.alreadyImported ? ' is-imported' : '') +
          (item.isPublic === false ? ' is-signed' : '') +
          '">' +
          '<div class="polymedia-mux-thumb">' +
          thumb +
          '</div>' +
          '<div class="polymedia-mux-meta">' +
          '<div class="title" title="' +
          Craft.escapeHtml(title) +
          '">' +
          Craft.escapeHtml(title) +
          '</div>' +
          '<div class="badges">' +
          badges +
          '</div>' +
          '</div>' +
          '<div class="polymedia-mux-actions">' +
          '<button type="button" class="btn small submit" data-action="import"' +
          (item.isPublic === false && !item.alreadyImported ? ' disabled' : '') +
          '>' +
          Craft.escapeHtml(actionLabel) +
          '</button>' +
          '</div>' +
          '</div>'
      );

      $card.find('[data-action="import"]').on('click', function () {
        self._import(item, $(this));
      });

      return $card;
    },

    _import: function (item, $btn) {
      var self = this;

      if (!item.assetId) {
        return;
      }

      $btn.addClass('loading').prop('disabled', true);

      Craft.sendActionRequest('POST', 'polymedia/mux/import', {
        data: {
          muxAssetId: item.assetId,
          folderId: this.folderId || '',
          title: item.title || '',
        },
      })
        .then(function (response) {
          var data = response.data || {};
          var message =
            data.message || Craft.t('polymedia', 'Mux media imported.');
          Craft.cp.displayNotice(message);
          Craft.Polymedia._refreshIndex(self.assetIndex);
          self.hide();
        })
        .catch(function (error) {
          $btn.removeClass('loading').prop('disabled', false);
          var message =
            (error &&
              error.response &&
              error.response.data &&
              error.response.data.message) ||
            Craft.t('app', 'A server error occurred.');
          Craft.cp.displayError(message);
        });
    },
  });

  /**
   * Modal: direct upload a video to Mux via UpChunk, then create `.pmedia`.
   */
  Craft.Polymedia.MuxUploadModal = Garnish.Modal.extend({
    assetIndex: null,
    folderId: null,
    uploadId: null,
    pollTimer: null,
    upchunk: null,
    busy: false,
    $title: null,
    $file: null,
    $chooseBtn: null,
    $fileName: null,
    $progress: null,
    $progressBar: null,
    $status: null,
    $startBtn: null,
    $cancelBtn: null,

    init: function (settings) {
      this.assetIndex = settings.assetIndex || null;
      this.folderId = settings.folderId || null;

      var $container = $(
        '<div class="modal polymedia-mux-upload-modal" role="dialog" aria-label="' +
          Craft.escapeHtml(Craft.t('polymedia', 'Upload to Mux')) +
          '"/>'
      );

      var $header = $(
        '<div class="header"><h1>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Upload to Mux')) +
          '</h1></div>'
      );

      var $body = $('<div class="body"/>');
      $body.append(
        $(
          '<div class="field">' +
            '<div class="heading"><label for="polymedia-mux-title">' +
            Craft.escapeHtml(Craft.t('polymedia', 'Title')) +
            '</label></div>' +
            '<div class="input"><input type="text" id="polymedia-mux-title" class="text fullwidth" autocomplete="off"/></div>' +
            '</div>'
        )
      );

      // Craft CP pattern (AssetIndex / element select / user photo):
      // hidden <input type="file"> + styled btn[data-icon=upload] that triggers it.
      var chooseLabel = Craft.t('app', 'Upload a file');
      var emptyFileLabel = Craft.t('polymedia', 'No file chosen');
      $body.append(
        $(
          '<div class="field">' +
            '<div class="heading"><label id="polymedia-mux-file-label">' +
            Craft.escapeHtml(Craft.t('polymedia', 'Video file')) +
            '</label></div>' +
            '<div class="input">' +
            '<div class="flex flex-nowrap polymedia-mux-file-picker">' +
            '<input type="file" id="polymedia-mux-file" class="hidden" accept="video/*,.mp4,.mov,.m4v,.webm,.mkv" aria-labelledby="polymedia-mux-file-label"/>' +
            '<button type="button" class="btn" data-icon="upload" data-action="choose-file" aria-controls="polymedia-mux-file">' +
            Craft.escapeHtml(chooseLabel) +
            '</button>' +
            '<span class="polymedia-mux-filename light" data-empty="' +
            Craft.escapeHtml(emptyFileLabel) +
            '">' +
            Craft.escapeHtml(emptyFileLabel) +
            '</span>' +
            '</div></div></div>'
        )
      );
      $body.append(
        $(
          '<p class="polymedia-mux-hint">' +
            Craft.escapeHtml(
              Craft.t(
                'polymedia',
                'Poster will be generated from the first frame when ready.'
              )
            ) +
            '</p>'
        )
      );

      this.$progress = $(
        '<div class="polymedia-mux-progress" hidden>' +
          '<div class="polymedia-mux-progress-track"><div class="polymedia-mux-progress-bar"/></div>' +
          '</div>'
      );
      this.$progressBar = this.$progress.find('.polymedia-mux-progress-bar');
      this.$status = $('<div class="polymedia-mux-upload-status"/>');
      $body.append(this.$progress, this.$status);

      this.$title = $body.find('#polymedia-mux-title');
      this.$file = $body.find('#polymedia-mux-file');
      this.$chooseBtn = $body.find('[data-action="choose-file"]');
      this.$fileName = $body.find('.polymedia-mux-filename');

      var $footer = $(
        '<div class="footer">' +
          '<div class="buttons right">' +
          '<button type="button" class="btn" data-action="cancel">' +
          Craft.escapeHtml(Craft.t('polymedia', 'Cancel')) +
          '</button>' +
          '<button type="button" class="btn submit" data-action="start">' +
          Craft.escapeHtml(Craft.t('polymedia', 'Start upload')) +
          '</button>' +
          '</div></div>'
      );

      this.$startBtn = $footer.find('[data-action="start"]');
      this.$cancelBtn = $footer.find('[data-action="cancel"]');

      $container.append($header, $body, $footer);

      this.base($container, {
        autoShow: false,
        hideOnShadeClick: false,
        shadeClass: 'modal-shade dark',
      });

      this.desiredWidth = Math.min(480, Math.max(360, Garnish.$win.width() - 48));
      // Keep shell tight after every Garnish layout pass (fade-in / resize).
      this.updateSizeAndPosition = function () {
        Garnish.Modal.prototype.updateSizeAndPosition.call(this);
        this._fitUploadShell();
      };
      this.show();

      this.addListener(this.$startBtn, 'click', 'startUpload');
      this.addListener(this.$cancelBtn, 'click', 'onCancel');
      this.addListener(this.$chooseBtn, 'click', 'onChooseFile');
      this.addListener(this.$file, 'change', 'onFileChange');
    },

    _fitUploadShell: function () {
      if (!this.$container || !this.$container.length) {
        return;
      }

      var gutter = this.settings.minGutter || 10;
      var maxH = Garnish.$win.height() - 2 * gutter;
      var width = Math.min(480, Math.max(360, Garnish.$win.width() - 48));
      this.desiredWidth = width;

      // Keep height:auto (clear Garnish’s locked min-height) so progress/status
      // rows can grow the shell without clipping. Re-center after measure.
      this.$container.css({
        width: width,
        minWidth: width,
        height: 'auto',
        minHeight: 0,
        maxHeight: maxH,
      });
      var height = Math.min(Math.max(this.$container.outerHeight(), 200), maxH);
      this.$container.css({
        left: Math.round((Garnish.$win.width() - width) / 2),
        top: Math.round((Garnish.$win.height() - height) / 2),
      });
    },

    onChooseFile: function () {
      if (this.busy || this.$chooseBtn.hasClass('disabled')) {
        return;
      }

      this.$file.trigger('click');
    },

    onFileChange: function () {
      var file = this.$file[0].files && this.$file[0].files[0];
      var emptyLabel = this.$fileName.data('empty') || '';

      if (!file) {
        this.$fileName.text(emptyLabel).addClass('light');
        return;
      }

      this.$fileName.text(file.name).removeClass('light');

      if (!this.$title.val()) {
        var name = file.name.replace(/\.[^.]+$/, '');
        this.$title.val(name);
      }

      // Content width may grow with a long filename.
      this._fitUploadShell();
    },

    startUpload: function () {
      var self = this;

      if (this.busy) {
        return;
      }

      var file = this.$file[0].files && this.$file[0].files[0];

      if (!file) {
        Craft.cp.displayError(Craft.t('polymedia', 'Choose a video file.'));
        return;
      }

      if (typeof UpChunk === 'undefined' || !UpChunk.createUpload) {
        Craft.cp.displayError(Craft.t('polymedia', 'Upload failed.'));
        return;
      }

      this.busy = true;
      this._setUiBusy(true);
      this.$progress.prop('hidden', false);
      this._setProgress(0);
      this._setStatus(Craft.t('polymedia', 'Uploading…'));

      Craft.sendActionRequest('POST', 'polymedia/mux/create-upload', {
        data: {
          title: this.$title.val() || '',
          folderId: this.folderId || '',
        },
      })
        .then(function (response) {
          var data = response.data || {};

          if (!data.uploadUrl || !data.uploadId) {
            throw new Error(Craft.t('polymedia', 'Upload failed.'));
          }

          self.uploadId = data.uploadId;
          if (data.folderId) {
            self.folderId = data.folderId;
          }

          self.upchunk = UpChunk.createUpload({
            endpoint: data.uploadUrl,
            file: file,
            chunkSize: 5120,
          });

          self.upchunk.on('progress', function (ev) {
            var pct =
              typeof ev.detail === 'number'
                ? ev.detail
                : (ev.detail && ev.detail.progress) || 0;
            self._setProgress(pct);
          });

          self.upchunk.on('error', function (ev) {
            var msg =
              (ev.detail && ev.detail.message) ||
              Craft.t('polymedia', 'Upload failed.');
            self._fail(msg);
          });

          self.upchunk.on('success', function () {
            self._setProgress(100);
            self._setStatus(Craft.t('polymedia', 'Processing on Mux…'));
            self._pollStatus();
          });
        })
        .catch(function (error) {
          var message =
            (error &&
              error.response &&
              error.response.data &&
              error.response.data.message) ||
            (error && error.message) ||
            Craft.t('polymedia', 'Upload failed.');
          self._fail(message);
        });
    },

    _pollStatus: function () {
      var self = this;
      var attempts = 0;
      var maxAttempts = 90;

      var tick = function () {
        if (!self.busy) {
          return;
        }

        attempts += 1;

        // POST + body `data` (Craft/axios GET `data` is not sent as query params,
        // so uploadId was missing and the endpoint returned 400 forever).
        Craft.sendActionRequest('POST', 'polymedia/mux/upload-status', {
          data: { uploadId: self.uploadId },
        })
          .then(function (response) {
            var data = response.data || {};

            if (data.failed) {
              self._fail(
                data.message || Craft.t('polymedia', 'Upload failed.')
              );
              return;
            }

            if (data.ready && data.assetId) {
              self._complete(data.assetId);
              return;
            }

            // Still waiting for Mux asset/playback id
            if (data.status) {
              self._setStatus(
                Craft.t('polymedia', 'Processing on Mux…') +
                  ' (' +
                  data.status +
                  ')'
              );
            }

            if (attempts >= maxAttempts) {
              self._fail(Craft.t('polymedia', 'Upload failed.'));
              return;
            }

            self.pollTimer = setTimeout(tick, 2000);
          })
          .catch(function (error) {
            var status =
              error && error.response && error.response.status
                ? error.response.status
                : 0;
            var message =
              (error &&
                error.response &&
                error.response.data &&
                error.response.data.message) ||
              Craft.t('polymedia', 'Upload failed.');

            // Auth/validation errors will not recover — stop immediately.
            if (status === 400 || status === 403 || status === 404) {
              self._fail(message);
              return;
            }

            if (attempts >= maxAttempts) {
              self._fail(message);
              return;
            }

            self.pollTimer = setTimeout(tick, 3000);
          });
      };

      tick();
    },

    _complete: function (muxAssetId) {
      var self = this;

      this._setStatus(Craft.t('polymedia', 'Creating media item…'));

      Craft.sendActionRequest('POST', 'polymedia/mux/complete-upload', {
        data: {
          muxAssetId: muxAssetId,
          uploadId: this.uploadId || '',
          folderId: this.folderId || '',
          title: this.$title.val() || '',
        },
      })
        .then(function (response) {
          var data = response.data || {};
          var message =
            data.message || Craft.t('polymedia', 'Mux upload complete.');
          Craft.cp.displayNotice(message);
          Craft.Polymedia._refreshIndex(self.assetIndex);
          self.busy = false;
          self.hide();
        })
        .catch(function (error) {
          var message =
            (error &&
              error.response &&
              error.response.data &&
              error.response.data.message) ||
            Craft.t('polymedia', 'Upload failed.');
          self._fail(message);
        });
    },

    _fail: function (message) {
      this.busy = false;
      this._clearTimers();
      this._abortUpchunk();
      this._setUiBusy(false);
      this._setStatus(message);
      Craft.cp.displayError(message);
    },

    onCancel: function () {
      if (this.busy) {
        this._abortUpchunk();
        this._clearTimers();
        this.busy = false;
        this._setUiBusy(false);
        this._setStatus(Craft.t('polymedia', 'Upload cancelled.'));
        Craft.cp.displayNotice(Craft.t('polymedia', 'Upload cancelled.'));
      }

      this.hide();
    },

    onFadeOut: function () {
      this._clearTimers();
      this._abortUpchunk();
      this.base();
    },

    _abortUpchunk: function () {
      if (this.upchunk && typeof this.upchunk.abort === 'function') {
        try {
          this.upchunk.abort();
        } catch (e) {
          // ignore
        }
      }

      this.upchunk = null;
    },

    _clearTimers: function () {
      if (this.pollTimer) {
        clearTimeout(this.pollTimer);
        this.pollTimer = null;
      }
    },

    _setUiBusy: function (busy) {
      this.$startBtn.prop('disabled', busy).toggleClass('loading', busy);
      this.$title.prop('disabled', busy);
      this.$file.prop('disabled', busy);
      this.$chooseBtn
        .prop('disabled', busy)
        .toggleClass('disabled', busy);
      this.$cancelBtn.text(
        busy
          ? Craft.t('polymedia', 'Cancel')
          : Craft.t('polymedia', 'Close')
      );
      this._fitUploadShell();
    },

    _setProgress: function (pct) {
      var value = Math.max(0, Math.min(100, Math.round(pct)));
      this.$progressBar.css('width', value + '%');
      this.$progress.attr('aria-valuenow', String(value));
    },

    _setStatus: function (text) {
      this.$status.text(text || '');
      // Status / progress visibility changes shell height — re-center after paint.
      var self = this;
      Garnish.requestAnimationFrame(function () {
        self._fitUploadShell();
      });
    },
  });

  Garnish.$doc.ready(function () {
    Craft.Polymedia.init();
  });
})();
