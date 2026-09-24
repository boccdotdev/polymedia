(function () {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  // Craft's modal callback does not return the field's async selection promise.
  // Keep the owning input so we can check its live state and await that promise.
  if (Craft.BaseElementSelectInput) {
    var getModalSettings = Craft.BaseElementSelectInput.prototype.getModalSettings;
    Craft.BaseElementSelectInput.prototype.getModalSettings = function () {
      var settings = getModalSettings.apply(this, arguments);
      settings.polymediaInput = this;
      return settings;
    };
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

      slideout.on('submit', async function (ev) {
        var assetId =
          ev && ev.response && ev.response.data
            ? ev.response.data.assetId
            : null;

        if (await Craft.Polymedia.finishSelection(assetIndex, assetId)) {
          Craft.cp.displayNotice(Craft.t('polymedia', 'Media item created.'));
        }
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

    /**
     * Whether the index lives inside an element selector modal (a field's
     * asset picker) rather than the standalone Assets index.
     *
     * @param {?Craft.AssetIndex} assetIndex
     * @returns {boolean}
     */
    isFieldPicker: function (assetIndex) {
      return !!(
        assetIndex &&
        assetIndex.settings &&
        assetIndex.settings.modal &&
        typeof assetIndex.settings.modal.selectElements === 'function'
      );
    },

    /**
     * Selects an asset into the field whose picker spawned this index.
     *
     * Uses the field's native insertion after checking the picker restrictions.
     * Eligibility is independent of the listing's search, page and folder.
     *
     * @param {?Craft.AssetIndex} assetIndex
     * @param {?number} assetId the Craft asset id to select
     * @returns {Promise<boolean>} whether the asset was selected
     */
    selectIntoField: async function (assetIndex, assetId) {
      if (!Craft.Polymedia.isFieldPicker(assetIndex)) {
        return false;
      }

      var selectorModal = assetIndex.settings.modal;
      var input = selectorModal.settings.polymediaInput;
      var id = Number(assetId);
      var siteId = assetIndex.siteId || Craft.siteId;

      if (
        !Number.isSafeInteger(id) ||
        id <= 0 ||
        !input ||
        input._polymediaSelecting
      ) {
        return false;
      }

      var canSelect = function () {
        var disabledIds = input.getDisabledElementIds().concat(
          assetIndex.settings.disabledElementIds || [],
          selectorModal.settings.disabledElementIds || []
        );
        var selectedIds = input.getSelectedElementIds();
        var replacementHasSlot = input._$replaceElement &&
          selectedIds.some(function (selectedId) {
            return Number(selectedId) === Number(input._$replaceElement.data('id'));
          }) &&
          (!input.settings.limit || selectedIds.length <= input.settings.limit);
        return (
          input.modal === selectorModal &&
          (assetIndex.siteId || Craft.siteId) === siteId &&
          !disabledIds.some(function (disabledId) {
            return Number(disabledId) === id;
          }) &&
          (input._$replaceElement ? replacementHasSlot : input.canAddMoreElements())
        );
      };

      if (!canSelect()) {
        return false;
      }

      input._polymediaSelecting = true;
      try {
        // These are the sources Craft actually exposed to this field, not the
        // current listing folder. Their data criteria also carry volume limits.
        var sources = assetIndex.$sources;
        var eligible = false;
        for (var i = 0; sources && i < sources.length; i++) {
          var $source = sources.eq(i);
          var sourceSites = $source.data('sites');
          if (
            $source.data('disabled') ||
            (sourceSites &&
              String(sourceSites).split(',').indexOf(String(siteId)) === -1)
          ) {
            continue;
          }
          var criteria = Object.assign(
            { status: null, drafts: false, siteId: siteId },
            $source.data('criteria') || {},
            { includeSubfolders: true },
            assetIndex.settings.criteria || {}
          );
          var response = await Craft.sendActionRequest('POST', 'polymedia/picker/check', {
            data: {
              assetId: id,
              context: 'modal',
              elementType: assetIndex.elementType,
              source: $source.data('key'),
              baseCriteria: criteria,
              condition: assetIndex.settings.condition,
              referenceElementId: assetIndex.settings.referenceElementId,
              referenceElementOwnerId: assetIndex.settings.referenceElementOwnerId,
              referenceElementSiteId: assetIndex.settings.referenceElementSiteId,
            },
          });
          if (response.data.selectable === true) {
            eligible = true;
            break;
          }
        }

        // State may have changed while the eligibility request was in flight.
        if (!eligible || !canSelect()) {
          return false;
        }

        try {
          if (input._$replaceElement) {
            await Craft.Polymedia._replaceIntoField(
              input, selectorModal, id, siteId, canSelect
            );
          } else {
            await input.onModalSelect([{ id: id, siteId: siteId }]);
          }
        } catch (error) {
          // Craft disables the picker before requesting the field HTML, but
          // does not restore it if that request rejects.
          if (input.modal === selectorModal) {
            selectorModal.enable();
            selectorModal.enableCancelBtn();
            selectorModal.enableSelectBtn();
            selectorModal.hideFooterSpinner();
          }
          if (input.elementEditor) {
            input.elementEditor.resume();
          }
          // Craft inserts before appending returned head/body HTML. A failure
          // in that later work does not undo a completed field selection.
          if (input.getSelectedElementIds().some(function (selectedId) {
            return Number(selectedId) === id;
          })) {
            if (input.modal === selectorModal) {
              selectorModal.hide();
            }
            return true;
          }
          throw error;
        }
        return input.getSelectedElementIds().some(function (selectedId) {
          return Number(selectedId) === id;
        });
      } finally {
        input._polymediaSelecting = false;
      }
    },

    // Native onModalSelect removes the replacement before requesting its new
    // HTML. Prepare everything first so a failed request cannot erase a value.
    _replaceIntoField: async function (input, modal, id, siteId, canSelect) {
      var $oldElement = input._$replaceElement;
      var viewMode = input.settings.viewMode;
      var cards = viewMode === 'cards' || viewMode === 'cards-grid';
      var large = viewMode === 'thumbs' || viewMode === 'large';

      modal.disable();
      modal.disableCancelBtn();
      modal.disableSelectBtn();
      modal.showFooterSpinner();
      if (input.elementEditor) {
        input.elementEditor.pause();
      }

      var response = await Craft.sendActionRequest('POST', 'app/render-elements', {
        data: {
          elements: [{
            type: input.settings.elementType,
            id: [id],
            siteId: siteId,
            instances: [{
              context: 'field',
              ui: cards ? 'card' : 'chip',
              size: cards ? null : (large ? 'large' : 'small'),
              showActionMenu: input.settings.showActionMenu,
            }],
          }],
        },
      });
      var data = response.data;
      var element = Craft.getElementInfo($(data.elements[id][0]));
      if (Number(element.id) !== id) {
        throw new Error('The rendered asset does not match the selection.');
      }
      var $newElement = input.createNewElement(element);

      // Load any required assets before changing the field value, too.
      await Craft.appendHeadHtml(data.headHtml);
      await Craft.appendBodyHtml(data.bodyHtml);
      if (input._$replaceElement !== $oldElement || !canSelect()) {
        throw new Error('The field changed while preparing the replacement.');
      }

      input.appendElement($newElement);
      $newElement.parent('li').insertBefore($oldElement.parent('li'));
      input.addElements($newElement);
      input.removeElement($oldElement);
      input._$replaceElement = null;
      element.$element = $newElement;
      input.onSelectElements([element]);
      input.updateDisabledElementsInModal();

      modal.enable();
      modal.enableCancelBtn();
      modal.enableSelectBtn();
      modal.hideFooterSpinner();
      modal.hide();
      if (input.elementEditor) {
        input.elementEditor.resume();
      }
    },

    // All creation paths await the same result before reporting success or
    // dismissing their dialog. A standalone Assets index only needs refreshing.
    finishSelection: async function (assetIndex, assetId) {
      if (!Craft.Polymedia.isFieldPicker(assetIndex)) {
        Craft.Polymedia._refreshIndex(assetIndex);
        return true;
      }

      try {
        if (await Craft.Polymedia.selectIntoField(assetIndex, assetId)) {
          return true;
        }
      } catch (error) {
        // Creation/import succeeded, but field insertion did not.
      }
      Craft.cp.displayError(Craft.t(
        'polymedia',
        'The media item could not be selected. It may already be selected, the field may be full, or the item may not meet the field restrictions.'
      ));
      return false;
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
    searchQuery: '',
    searchTimer: null,
    requestId: 0, // stale-response guard; only the newest request renders
    $body: null,
    $searchInput: null,
    $searchClear: null,
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

      // Field pickers get Select actions on already-imported cards.
      $container.toggleClass(
        'is-field-picker',
        Craft.Polymedia.isFieldPicker(this.assetIndex)
      );

      var $header = $(
        '<div class="header">' +
          '<h1>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Browse Mux library')) +
          '</h1>' +
          '</div>'
      );

      this.$body = $('<div class="body"/>');

      // Native CP search input (texticon + clear button), filtering the
      // library server-side since the Mux list API has no search of its own.
      var $search = $(
        '<div class="texticon search icon clearable polymedia-mux-search">' +
          '<input type="text" class="text fullwidth" autocomplete="off" placeholder="' +
          Craft.escapeHtml(Craft.t('app', 'Search')) +
          '" aria-label="' +
          Craft.escapeHtml(Craft.t('polymedia', 'Search videos')) +
          '"/>' +
          '<button type="button" class="clear-btn hidden" title="' +
          Craft.escapeHtml(Craft.t('app', 'Clear search')) +
          '" aria-label="' +
          Craft.escapeHtml(Craft.t('app', 'Clear search')) +
          '"></button>' +
          '</div>'
      ).appendTo(this.$body);
      this.$searchInput = $search.find('input');
      this.$searchClear = $search.find('.clear-btn');

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
      this.addListener(this.$searchInput, 'textchange', 'onSearchChange');
      this.addListener(this.$searchClear, 'click', 'onSearchClear');
      this.loadPage(1);
    },

    onSearchChange: function () {
      var self = this;
      var value = String(this.$searchInput.val() || '');

      this.$searchClear.toggleClass('hidden', value === '');

      if (this.searchTimer) {
        clearTimeout(this.searchTimer);
      }

      // Debounce so a keystroke burst becomes one request.
      this.searchTimer = setTimeout(function () {
        self.searchTimer = null;
        var query = String(self.$searchInput.val() || '').trim();

        if (query !== self.searchQuery) {
          self.searchQuery = query;
          self.loadPage(1);
        }
      }, 350);
    },

    onSearchClear: function () {
      this.$searchInput.val('').trigger('focus');
      this.$searchClear.addClass('hidden');

      if (this.searchTimer) {
        clearTimeout(this.searchTimer);
        this.searchTimer = null;
      }

      if (this.searchQuery !== '') {
        this.searchQuery = '';
        this.loadPage(1);
      }
    },

    onFadeOut: function () {
      if (this.searchTimer) {
        clearTimeout(this.searchTimer);
        this.searchTimer = null;
      }

      // Drop any in-flight response.
      this.requestId++;
      this.base();
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
      // Requests may overlap while typing — only the newest response renders.
      var requestId = ++this.requestId;
      var search = this.searchQuery;

      this.page = page;
      this.$status.text(Craft.t('polymedia', 'Loading Mux library…'));
      this.$grid.empty();
      this.$pager.empty();

      // GET query args go in `params`; `data` becomes a request body that the
      // server ignores, so page/limit would silently fall back to defaults.
      var params = { page: page, limit: this.limit };

      if (search) {
        params.search = search;
      }

      Craft.sendActionRequest('GET', 'polymedia/mux/library', {
        params: params,
      })
        .then(function (response) {
          if (requestId !== self.requestId) {
            return;
          }

          var data = response.data || {};
          var items = data.items || [];

          self.$status.empty();

          if (!items.length) {
            self.$status.text(
              search
                ? Craft.t('polymedia', 'No videos match your search.')
                : Craft.t('polymedia', 'No Mux assets found.')
            );
            self.updateSizeAndPosition();
            return;
          }

          if (search && data.scanLimited) {
            self.$status.text(
              Craft.t(
                'polymedia',
                'Search is limited to the {count} most recent videos.',
                { count: data.scanLimit || 1000 }
              )
            );
          }

          items.forEach(function (item) {
            self.$grid.append(self._card(item));
          });

          self._renderPager(
            data.page || page,
            items.length,
            search ? data.total : null
          );
          self.updateSizeAndPosition();
        })
        .catch(function (error) {
          if (requestId !== self.requestId) {
            return;
          }

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

    /**
     * @param {number} page current page
     * @param {number} count items on this page
     * @param {?number} total exact match count (search), or null when the
     *                        plain listing's has-more is inferred from count
     */
    _renderPager: function (page, count, total) {
      var self = this;
      this.$pager.empty();

      var hasNext =
        typeof total === 'number'
          ? page * this.limit < total
          : count >= this.limit;

      if (page <= 1 && !hasNext) {
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
          (!hasNext ? ' disabled' : '') +
          '>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Next')) +
          '</button>'
      );

      if (page > 1) {
        $prev.on('click', function () {
          self.loadPage(page - 1);
        });
      }

      if (hasNext) {
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

      // In a field picker, an already-imported asset is directly selectable;
      // on the plain Assets index there is nothing further to do with it.
      var inFieldPicker = Craft.Polymedia.isFieldPicker(this.assetIndex);
      var canSelectExisting =
        inFieldPicker && item.alreadyImported && item.craftAssetId;

      var actionLabel;
      var actionDisabled = false;

      if (canSelectExisting) {
        actionLabel = Craft.t('app', 'Select');
      } else if (item.alreadyImported) {
        actionLabel = Craft.t('polymedia', 'In Craft');
        actionDisabled = true;
      } else {
        actionLabel = Craft.t('polymedia', 'Import');
        actionDisabled = item.isPublic === false;
      }

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
          (actionDisabled ? ' disabled' : '') +
          '>' +
          Craft.escapeHtml(actionLabel) +
          '</button>' +
          '</div>' +
          '</div>'
      );

      var $importBtn = $card.find('[data-action="import"]');

      if (canSelectExisting) {
        $importBtn.on('click', async function () {
          $importBtn.addClass('loading').prop('disabled', true);
          if (await Craft.Polymedia.finishSelection(self.assetIndex, item.craftAssetId)) {
            self.hide();
          }
          $importBtn.removeClass('loading').prop('disabled', false);
        });
      } else if (!actionDisabled) {
        $importBtn.on('click', function () {
          self._import(item, $importBtn);
        });
      }

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
        .then(async function (response) {
          var data = response.data || {};
          var message =
            data.message || Craft.t('polymedia', 'Mux media imported.');

          if (!await Craft.Polymedia.finishSelection(self.assetIndex, data.assetId)) {
            $btn.removeClass('loading').prop('disabled', false);
            return;
          }

          Craft.cp.displayNotice(message);
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
        .then(async function (response) {
          var data = response.data || {};
          var message =
            data.message || Craft.t('polymedia', 'Mux upload complete.');

          if (!await Craft.Polymedia.finishSelection(self.assetIndex, data.assetId)) {
            self.busy = false;
            self._setUiBusy(false);
            self._setStatus(Craft.t('polymedia', 'Media created, but not selected.'));
            return;
          }

          Craft.cp.displayNotice(message);
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
