(function () {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  Craft.Polymedia = {
    init: function () {
      Garnish.on(Craft.AssetIndex, 'afterInit', function (ev) {
        Craft.Polymedia.injectButton(ev.target);
      });
    },

    injectButton: function (assetIndex) {
      if (assetIndex.modal || assetIndex.$container.closest('.modal, .slideout').length) {
        return;
      }

      var $toolbar = assetIndex.$toolbar;

      if (!$toolbar || !$toolbar.length) {
        return;
      }

      var $uploadBtn = $toolbar.find('.btn[data-action="upload"]');

      if (!$uploadBtn.length) {
        $uploadBtn = $toolbar.find('.btn.submit').first();
      }

      var $btn = $(
        '<button type="button" class="btn polymedia-add-btn">' +
          Craft.t('polymedia', 'Add media URL') +
          '</button>'
      );

      if ($uploadBtn.length) {
        $uploadBtn.after($btn);
      } else {
        $toolbar.append($btn);
      }

      $btn.on('click', function () {
        Craft.Polymedia.openSlideout();
      });
    },

    openSlideout: function () {
      var slideout = new Craft.CpScreenSlideout(
        'polymedia/media-items/create-screen'
      );

      slideout.on('submit', function () {
        Craft.cp.displayNotice(Craft.t('polymedia', 'Media item created.'));

        if (Craft.elementIndex) {
          Craft.elementIndex.updateElements();
        }
      });
    },
  };

  Garnish.$doc.ready(function () {
    Craft.Polymedia.init();
  });
})();
