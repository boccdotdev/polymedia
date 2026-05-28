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

      if (assetIndex.settings && assetIndex.settings.context === 'index') {
        // Standalone Assets index: sit just before the "Upload files" button.
        // Craft re-creates that button on every source change, so (re)place
        // ours relative to the current one each time to keep the order stable.
        var place = function () {
          var $upload = assetIndex.$uploadButton;

          if ($upload && $upload.length) {
            $upload.before($btn);
          } else {
            assetIndex.addButton($btn);
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
      }

      assetIndex._polymediaBtnInjected = true;

      $btn.on('click', function () {
        Craft.Polymedia.openSlideout(assetIndex);
      });
    },

    openSlideout: function (assetIndex) {
      var params = {};

      if (assetIndex) {
        var folderId = assetIndex.currentFolderId;

        if (!folderId && assetIndex.$source) {
          folderId = assetIndex.$source.data('folder-id');
        }

        if (folderId) {
          params.folderId = folderId;
        }
      }

      var slideout = new Craft.CpScreenSlideout(
        'polymedia/media-items/create-screen',
        { params: params }
      );

      slideout.on('submit', function () {
        Craft.cp.displayNotice(Craft.t('polymedia', 'Media item created.'));

        if (assetIndex) {
          assetIndex.updateElements();
        } else if (Craft.elementIndex) {
          Craft.elementIndex.updateElements();
        }
      });
    },
  };

  Garnish.$doc.ready(function () {
    Craft.Polymedia.init();
  });
})();
