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

      var $btn = $(
        '<button type="button" class="btn polymedia-add-btn" data-icon="plus">' +
          Craft.t('polymedia', 'Add media URL') +
          '</button>'
      );

      var $muxBtn = null;

      if (Craft.Polymedia.muxEnabled) {
        $muxBtn = $(
          '<button type="button" class="btn polymedia-mux-browse-btn">' +
            Craft.t('polymedia', 'Browse Mux library') +
            '</button>'
        );
      }

      if (assetIndex.settings && assetIndex.settings.context === 'index') {
        // Standalone Assets index: sit just before the "Upload files" button.
        // Craft re-creates that button on every source change, so (re)place
        // ours relative to the current one each time to keep the order stable.
        var place = function () {
          var $upload = assetIndex.$uploadButton;

          if ($upload && $upload.length) {
            $upload.before($btn);
            if ($muxBtn) {
              $btn.after($muxBtn);
            }
          } else {
            assetIndex.addButton($btn);
            if ($muxBtn) {
              assetIndex.addButton($muxBtn);
            }
          }
        };

        place();
        assetIndex.on('selectSource', place);
      } else {
        // Selection modal: sit in the toolbar beside its upload button.
        var $toolbar = assetIndex.$toolbar;

        if (!$toolbar || !$toolbar.length) {
          return;
        }

        var $uploadBtn = $toolbar.find('.btn[data-action="upload"]');

        if (!$uploadBtn.length) {
          $uploadBtn = $toolbar.find('.btn.submit').first();
        }

        if ($uploadBtn.length) {
          $uploadBtn.after($btn);
        } else {
          $toolbar.append($btn);
        }

        if ($muxBtn) {
          $btn.after($muxBtn);
        }
      }

      assetIndex._polymediaBtnInjected = true;

      $btn.on('click', function () {
        Craft.Polymedia.openSlideout(assetIndex);
      });

      if ($muxBtn) {
        $muxBtn.on('click', function () {
          Craft.Polymedia.openMuxBrowse(assetIndex);
        });
      }
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

      var $container = $(
        '<div class="modal fitted polymedia-mux-modal" role="dialog" aria-label="' +
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
        hideOnShadeClick: true,
        shadeClass: 'modal-shade dark',
      });

      this.addListener($footer.find('[data-action="close"]'), 'click', 'hide');
      this.loadPage(1);
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

      Craft.sendActionRequest('GET', 'polymedia/mux/library', {
        data: { page: page, limit: this.limit },
      })
        .then(function (response) {
          self.loading = false;
          var data = response.data || {};
          var items = data.items || [];

          self.$status.empty();

          if (!items.length) {
            self.$status.text(Craft.t('polymedia', 'No Mux assets found.'));
            return;
          }

          items.forEach(function (item) {
            self.$grid.append(self._card(item));
          });

          self._renderPager(data.page || page, items.length);
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

  Garnish.$doc.ready(function () {
    Craft.Polymedia.init();
  });
})();
