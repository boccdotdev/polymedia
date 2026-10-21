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

  // Select inputs fire no init event. Assets fields that offer video or audio
  // render their menu config beside the input instead (FieldMedia::getMenu()).
  if (Craft.AssetSelectInput) {
    var initAssetSelectInput = Craft.AssetSelectInput.prototype.init;
    Craft.AssetSelectInput.prototype.init = function () {
      initAssetSelectInput.apply(this, arguments);
      Craft.Polymedia.initFieldButton(this);
    };
  }

  /*
   * Every Add video or audio item opens with a destination, which says where
   * the new or reused `.pmedia` goes and what happens to it:
   *
   * - `folderId`, `siteId`, `fieldId`, `elementId`: sent to the server, which
   *   authorizes them again;
   * - `selects`: whether the result is selected into a field;
   * - `canAccept()`: whether the field can still take an item;
   * - `finish(assetId)`: selects the asset or refreshes the index, and
   *   resolves false to keep the dialog open.
   */
  Craft.Polymedia = {
    // Set from PHP. These flags control presentation, never authorization.
    isPro: !!(window.CraftPolymediaConfig && window.CraftPolymediaConfig.isPro),
    videoProvider: (window.CraftPolymediaConfig || {}).videoProvider || 'mux',
    videoEnabled: !!(window.CraftPolymediaConfig || {}).videoEnabled,

    /**
     * Returns the selected hosting provider's controller action.
     *
     * @param {string} action e.g. `library`
     * @returns {string}
     */
    videoAction: function (action) {
      return 'polymedia/' + Craft.Polymedia.videoProvider + '/' + action;
    },

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

    /**
     * Returns the Add video or audio items, in menu order.
     *
     * @param {?string[]} allowed the item keys a field offers, or null for all
     * @returns {Array<{key: string, label: string, description: string, open: string}>}
     */
    menuItems: function (allowed) {
      var items = [
        {
          key: 'url',
          label: Craft.t('polymedia', 'From URL'),
          description: Craft.t(
            'polymedia',
            'Paste a YouTube, Vimeo, HLS, or other media URL'
          ),
          open: 'openSlideout',
        },
        {
          key: 'existing',
          label: Craft.t('polymedia', 'From existing asset'),
          description: Craft.t(
            'polymedia',
            'Use a video asset without changing the original file'
          ),
          open: 'openSourceAsset',
        },
      ];

      if (Craft.Polymedia.videoEnabled) {
        items.push(
          {
            key: 'library',
            label: Craft.t('polymedia', 'Browse video library'),
            description: Craft.t(
              'polymedia',
              'Import a video from your video library'
            ),
            open: 'openVideoBrowse',
          },
          {
            key: 'upload',
            label: Craft.t('polymedia', 'Upload video'),
            description: Craft.t(
              'polymedia',
              'Upload a video file to your video library'
            ),
            open: 'openVideoUpload',
          }
        );
      }

      return items.filter(function (item) {
        return !allowed || allowed.indexOf(item.key) !== -1;
      });
    },

    injectButton: function (assetIndex) {
      if (!assetIndex || assetIndex._polymediaBtnInjected) {
        return;
      }

      assetIndex._polymediaBtnInjected = true;

      var items = Craft.Polymedia._indexMenuItems(assetIndex);

      if (!items || !items.length) {
        return;
      }

      // Single disclosure beside Craft’s “Upload files” (volume file upload).
      var menu = Craft.Polymedia._createMenuButton('polymedia-add-media-btn');
      var $btn = menu.$btn;
      var $menu = menu.$menu;
      var getDestination = function () {
        return Promise.resolve(Craft.Polymedia.indexDestination(assetIndex));
      };

      var place = function () {
        // Native video routing reads the index's destination from its uploader.
        if (assetIndex.$uploadButton) {
          assetIndex.$uploadButton.data('polymediaIndex', assetIndex);
        }

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
          Craft.Polymedia._initMenu($btn, $menu, items, getDestination);
        }
      };

      place();

      if (typeof assetIndex.on === 'function') {
        // Craft rebuilds the upload button on source change, in indexes and pickers.
        assetIndex.on('selectSource', place);
      }
    },

    /**
     * Returns the items an index offers, or null for none.
     *
     * A field's picker follows its field: the field's own items when it
     * offers video or audio, and none when it can't hold `.pmedia` assets.
     * Selector modals without a field, such as From existing asset's, have
     * nothing to select into and get no menu.
     *
     * @param {Craft.AssetIndex} assetIndex
     * @returns {?Array}
     */
    _indexMenuItems: function (assetIndex) {
      if (!Craft.Polymedia.isFieldPicker(assetIndex)) {
        return Craft.Polymedia.menuItems(null);
      }

      var input = assetIndex.settings.modal.settings.polymediaInput;

      if (!input || !input.settings) {
        return null;
      }

      if (input._polymediaFieldMedia) {
        return Craft.Polymedia.menuItems(input._polymediaFieldMedia.items || []);
      }

      var kinds = input.settings.criteria && input.settings.criteria.kind;

      if (kinds && kinds.length && Array.from(kinds).indexOf('polymedia') === -1) {
        return null;
      }

      return Craft.Polymedia.menuItems(null);
    },

    /**
     * Adds the Add video or audio menu beside a field's Add an asset and
     * Upload a file buttons, when the server rendered the field's menu.
     *
     * @param {Craft.AssetSelectInput} input
     */
    initFieldButton: function (input) {
      if (!input || !input.$container || input._polymediaFieldMedia) {
        return;
      }

      var $marker = input.$container.nextAll('[data-polymedia-field-media]').first();
      var config = $marker.length ? $marker.data('polymediaFieldMedia') : null;

      if (!config || typeof config !== 'object') {
        return;
      }

      // The field's picker offers the same items.
      input._polymediaFieldMedia = config;

      var items = Craft.Polymedia.menuItems(config.items || []);
      var $addBtn = input.$addElementBtn;

      if (
        !config.canAdd ||
        !items.length ||
        !input.settings.fieldId ||
        !$addBtn ||
        !$addBtn.length ||
        $addBtn.prop('disabled')
      ) {
        return;
      }

      var menu = Craft.Polymedia._createMenuButton('dashed polymedia-field-media-btn');
      // Upload a file opens the file input right after it, so go after both.
      var $anchor = input.$uploadBtn
        ? input.$uploadBtn.nextAll('input[type=file]').first()
        : $();

      if (!$anchor.length) {
        $anchor = input.$uploadBtn || $addBtn;
      }

      menu.$btn.insertAfter($anchor);
      Craft.Polymedia._initMenu(menu.$btn, menu.$menu, items, function () {
        return Craft.Polymedia.fieldDestination(input);
      });

      // Hide and show with Craft's own buttons as the field fills and empties.
      ['enableAddElementsBtn', 'disableAddElementsBtn'].forEach(function (method) {
        var original = input[method];
        input[method] = function () {
          menu.$btn.toggleClass('hidden', method === 'disableAddElementsBtn');
          return original.apply(this, arguments);
        };
      });
      input.updateAddElementsBtn();
    },

    /**
     * Returns an Add video or audio disclosure button and its menu.
     *
     * @param {string} classes extra button classes
     * @returns {{$btn: jQuery, $menu: jQuery}}
     */
    _createMenuButton: function (classes) {
      var menuId =
        'polymedia-add-media-' + Math.floor(Math.random() * 1000000);

      return {
        $btn: $(
          '<button type="button" class="btn menubtn add icon ' +
            classes +
            '" data-disclosure-trigger aria-controls="' +
            menuId +
            '" aria-haspopup="true">' +
            Craft.escapeHtml(Craft.t('polymedia', 'Add video or audio')) +
            '</button>'
        ),
        $menu: $(
          '<div id="' +
            menuId +
            '" class="menu menu--disclosure"><ul></ul></div>'
        ),
      };
    },

    /**
     * Wires a placed button to its menu. The menu moves to `<body>`.
     *
     * @param {jQuery} $btn
     * @param {jQuery} $menu
     * @param {Array} items from menuItems()
     * @param {function(): Promise<?Object>} getDestination
     */
    _initMenu: function ($btn, $menu, items, getDestination) {
      $btn.after($menu);
      $btn.disclosureMenu();
      Craft.Polymedia._populateAddMediaMenu($menu, items, getDestination);
    },

    /**
     * Fills the Add video or audio disclosure with native DisclosureMenu
     * items, so activate handlers are wired the same way as native CP menus
     * (delegated activate fails).
     *
     * @param {jQuery} $menu
     * @param {Array} items from menuItems()
     * @param {function(): Promise<?Object>} getDestination
     */
    _populateAddMediaMenu: function ($menu, items, getDestination) {
      var disclosure = $menu.data('disclosureMenu');

      if (!disclosure || typeof disclosure.addItem !== 'function') {
        return;
      }

      // Empty the placeholder list before adding wired items.
      $menu.children('ul').empty();

      items.forEach(function (item) {
        disclosure.addItem({
          label: item.label,
          description: item.description,
          onActivate: function () {
            Craft.Polymedia.open(item, getDestination);
          },
        });
      });
    },

    /**
     * Resolves the destination, then opens an item's dialog.
     *
     * @param {{open: string}} item from menuItems()
     * @param {function(): Promise<?Object>} getDestination
     * @returns {Promise<void>}
     */
    open: async function (item, getDestination) {
      var destination;

      try {
        destination = await getDestination();
      } catch (error) {
        Craft.cp.displayError(Craft.Polymedia._errorMessage(
          error,
          Craft.t('polymedia', 'Could not create media item.')
        ));
        return;
      }

      if (destination) {
        Craft.Polymedia[item.open](destination);
      }
    },

    _errorMessage: function (error, fallback) {
      return (
        (error && error.response && error.response.data && error.response.data.message) ||
        fallback
      );
    },

    openSlideout: function (destination) {
      var params = {};

      if (destination.folderId) {
        params.folderId = destination.folderId;
      }

      // Narrows the offered types to the field's allowed providers.
      if (destination.fieldId) {
        params.fieldId = destination.fieldId;
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

        if (await destination.finish(assetId)) {
          Craft.cp.displayNotice(Craft.t('polymedia', 'Media item created.'));
        }
      });
    },

    openVideoBrowse: function (destination) {
      if (!Craft.Polymedia.videoEnabled) {
        return;
      }

      new Craft.Polymedia.VideoBrowseModal({ destination: destination });
    },

    openVideoUpload: function (destination) {
      if (!Craft.Polymedia.videoEnabled) {
        return;
      }

      new Craft.Polymedia.VideoUploadModal({ destination: destination });
    },

    /**
     * Picks a Craft video and wraps it in a `.pmedia` reference.
     *
     * @param {Object} destination
     */
    openSourceAsset: function (destination) {
      Craft.createElementSelectorModal('craft\\elements\\Asset', {
        multiSelect: false,
        criteria: { kind: 'video', siteId: destination.siteId || Craft.siteId },
        onSelect: async function (elements) {
          if (!elements || !elements.length) {
            return;
          }

          try {
            var response = await Craft.sendActionRequest('POST', 'polymedia/source-assets/import', {
              data: {
                sourceAssetId: elements[0].id,
                folderId: destination.folderId,
              },
            });

            if (await destination.finish(response.data.assetId)) {
              Craft.cp.displayNotice(Craft.t('polymedia', 'Media item created.'));
            }
          } catch (error) {
            Craft.cp.displayError(Craft.Polymedia._errorMessage(
              error,
              Craft.t('polymedia', 'Could not create media item.')
            ));
          }
        },
      });
    },

    /**
     * Returns the destination for an Assets index or field picker: the
     * folder being browsed, selected into the picker's field if any.
     *
     * @param {?Craft.AssetIndex} assetIndex
     * @returns {Object}
     */
    indexDestination: function (assetIndex) {
      return Object.assign(Craft.Polymedia.uploadContext(assetIndex), {
        selects: Craft.Polymedia.isFieldPicker(assetIndex),
        canAccept: function () {
          return Craft.Polymedia._pickerCanAccept(assetIndex);
        },
        finish: function (assetId) {
          return Craft.Polymedia.finishSelection(assetIndex, assetId);
        },
      });
    },

    /**
     * Resolves a field's destination: its upload location for the element
     * being edited, as the server resolves and authorizes it.
     *
     * @param {Craft.AssetSelectInput} input
     * @returns {Promise<?Object>} null while the field is full or busy
     */
    fieldDestination: async function (input) {
      if (input._polymediaResolving || !Craft.Polymedia._fieldCanAccept(input)) {
        return null;
      }

      var context = Craft.Polymedia.fieldContext(input);
      input._polymediaResolving = true;

      try {
        var response = await Craft.sendActionRequest('POST', 'polymedia/field-media/destination', {
          data: context,
        });

        return Object.assign({}, context, {
          folderId: response.data.folderId,
          selects: true,
          canAccept: function () {
            return Craft.Polymedia._fieldCanAccept(input);
          },
          finish: function (assetId) {
            return Craft.Polymedia.finishFieldSelection(input, assetId);
          },
        });
      } finally {
        input._polymediaResolving = false;
      }
    },

    /**
     * Returns the field, element and site a field's input edits.
     *
     * The element editor may have swapped the element for a draft since the
     * field rendered.
     *
     * @param {Craft.BaseElementSelectInput} input
     * @returns {{fieldId: number, elementId: ?number, siteId: number}}
     */
    fieldContext: function (input) {
      var settings = input.settings || {};
      var elementId = settings.sourceElementId || null;

      if (elementId && input.elementEditor && typeof input.elementEditor.getDraftElementId === 'function') {
        elementId = input.elementEditor.getDraftElementId(elementId) || elementId;
      }

      return {
        fieldId: settings.fieldId,
        elementId: elementId,
        siteId: settings.referenceElementSiteId || Craft.Polymedia._targetSiteId(input),
      };
    },

    // The site the field relates its assets in.
    _targetSiteId: function (input) {
      return (input.settings && input.settings.criteria && input.settings.criteria.siteId) || Craft.siteId;
    },

    /**
     * Returns the upload destination context for preflight: the folder, and
     * the field and element when opened from a field's asset picker.
     *
     * @param {?Craft.AssetIndex} assetIndex
     * @returns {Object}
     */
    uploadContext: function (assetIndex) {
      var context = {
        folderId: Craft.Polymedia._folderId(assetIndex),
        siteId: (assetIndex && assetIndex.siteId) || Craft.siteId,
      };

      if (Craft.Polymedia.isFieldPicker(assetIndex)) {
        var input = assetIndex.settings.modal.settings.polymediaInput;

        if (input && input.settings) {
          context.fieldId = input.settings.fieldId;
          context.elementId = input.settings.sourceElementId;
        }
      }

      return context;
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

    // Whether the picker's field can take another item, or replace one.
    _pickerCanAccept: function (assetIndex) {
      if (!Craft.Polymedia.isFieldPicker(assetIndex)) {
        return true;
      }

      var modal = assetIndex.settings.modal;
      var input = modal.settings.polymediaInput;
      var replacing = input && input._$replaceElement &&
        input.getSelectedElementIds().some(function (id) {
          return Number(id) === Number(input._$replaceElement.data('id'));
        });

      return !!input && input.modal === modal && (replacing || input.canAddMoreElements());
    },

    // Whether a field can take another item from its own menu.
    _fieldCanAccept: function (input) {
      return !input._$replaceElement && input.canAddMoreElements();
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

    /**
     * Selects an asset into a field's input without opening its picker.
     *
     * The server checks the field's rules for the element being edited, then
     * the input adds the asset the way its picker would.
     *
     * @param {Craft.AssetSelectInput} input
     * @param {?number} assetId the Craft asset id to select
     * @returns {Promise<boolean>} whether the asset was selected
     */
    selectIntoInput: async function (input, assetId) {
      var id = Number(assetId);

      if (!Number.isSafeInteger(id) || id <= 0 || input._polymediaSelecting) {
        return false;
      }

      var canSelect = function () {
        return (
          Craft.Polymedia._fieldCanAccept(input) &&
          !input.getDisabledElementIds().some(function (disabledId) {
            return Number(disabledId) === id;
          })
        );
      };

      if (!canSelect()) {
        return false;
      }

      input._polymediaSelecting = true;
      try {
        var response = await Craft.sendActionRequest('POST', 'polymedia/field-media/check', {
          data: Object.assign(Craft.Polymedia.fieldContext(input), { assetId: id }),
        });

        // State may have changed while the eligibility request was in flight.
        if (response.data.selectable !== true || !canSelect()) {
          return false;
        }

        // One draft save for the whole selection, as with the picker.
        if (input.elementEditor) {
          input.elementEditor.pause();
        }
        try {
          var element = await Craft.Polymedia._renderFieldElement(
            input, id, Craft.Polymedia._targetSiteId(input)
          );
          if (!canSelect()) {
            return false;
          }
          await input.selectElements([element]);
          input.updateDisabledElementsInModal();
        } finally {
          if (input.elementEditor) {
            input.elementEditor.resume();
          }
        }

        return input.getSelectedElementIds().some(function (selectedId) {
          return Number(selectedId) === id;
        });
      } finally {
        input._polymediaSelecting = false;
      }
    },

    // Renders an asset the way the input shows its elements and loads what
    // it needs, before anything changes the field value.
    _renderFieldElement: async function (input, id, siteId) {
      var viewMode = input.settings.viewMode;
      var cards = viewMode === 'cards' || viewMode === 'cards-grid';
      var large = viewMode === 'thumbs' || viewMode === 'large';

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

      await Craft.appendHeadHtml(data.headHtml);
      await Craft.appendBodyHtml(data.bodyHtml);

      return element;
    },

    // Native onModalSelect removes the replacement before requesting its new
    // HTML. Prepare everything first so a failed request cannot erase a value.
    _replaceIntoField: async function (input, modal, id, siteId, canSelect) {
      var $oldElement = input._$replaceElement;

      modal.disable();
      modal.disableCancelBtn();
      modal.disableSelectBtn();
      modal.showFooterSpinner();
      if (input.elementEditor) {
        input.elementEditor.pause();
      }

      var element = await Craft.Polymedia._renderFieldElement(input, id, siteId);
      var $newElement = input.createNewElement(element);
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

      return Craft.Polymedia._reportSelection(
        Craft.Polymedia.selectIntoField(assetIndex, assetId)
      );
    },

    finishFieldSelection: function (input, assetId) {
      return Craft.Polymedia._reportSelection(
        Craft.Polymedia.selectIntoInput(input, assetId)
      );
    },

    _reportSelection: async function (selection) {
      try {
        if (await selection) {
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
   * Modal: the selected provider's video library with import / reuse.
   */
  Craft.Polymedia.VideoBrowseModal = Garnish.Modal.extend({
    destination: null,
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
      this.destination = settings.destination;

      // Not “fitted”: empty/loading states need a stable wide shell (CSS min-width).
      var $container = $(
        '<div class="modal polymedia-mux-modal" role="dialog" aria-label="' +
          Craft.escapeHtml(Craft.t('polymedia', 'Browse video library')) +
          '"/>'
      );

      // Fields get Select actions on already-imported cards.
      $container.toggleClass('is-field-picker', !!this.destination.selects);

      var $header = $(
        '<div class="header">' +
          '<h1>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Browse video library')) +
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
      this.$status.text(Craft.t('polymedia', 'Loading video library…'));
      this.$grid.empty();
      this.$pager.empty();

      // GET query args go in `params`; `data` becomes a request body that the
      // server ignores, so page/limit would silently fall back to defaults.
      var params = { page: page, limit: this.limit };

      if (search) {
        params.search = search;
      }

      Craft.sendActionRequest('GET', Craft.Polymedia.videoAction('library'), {
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
                : Craft.t('polymedia', 'No videos found.')
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
            Craft.t('polymedia', 'Could not load video library.');
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

      // For a field, an already-imported asset is directly selectable; on
      // the plain Assets index there is nothing further to do with it.
      var canSelectExisting =
        this.destination.selects && item.alreadyImported && item.craftAssetId;

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
          if (await self.destination.finish(item.craftAssetId)) {
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

      var data = {
        folderId: this.destination.folderId || '',
        title: item.title || '',
      };
      data[Craft.Polymedia.videoProvider === 'bunny' ? 'videoId' : 'muxAssetId'] = item.assetId;

      Craft.sendActionRequest('POST', Craft.Polymedia.videoAction('import'), {
        data: data,
      })
        .then(async function (response) {
          var data = response.data || {};
          var message =
            data.message || Craft.t('polymedia', 'Video imported.');

          if (!await self.destination.finish(data.assetId)) {
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
   * Modal: authorize, upload to the selected provider, then select the `.pmedia`.
   */
  Craft.Polymedia.VideoUploadModal = Garnish.Modal.extend({
    destination: null,
    uploadTask: null,
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
      this.destination = settings.destination;

      var $container = $(
        '<div class="modal polymedia-mux-upload-modal" role="dialog" aria-label="' +
          Craft.escapeHtml(Craft.t('polymedia', 'Upload video')) +
          '"/>'
      );

      var $header = $(
        '<div class="header"><h1>' +
          Craft.escapeHtml(Craft.t('polymedia', 'Upload video')) +
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

      // For a field, only upload what the field can still accept.
      if (!this.destination.canAccept()) {
        Craft.cp.displayError(Craft.t('polymedia', 'The field cannot accept another media item.'));
        return;
      }

      if (!Craft.PolymediaVideoUpload) {
        Craft.cp.displayError(Craft.t('polymedia', 'Upload failed.'));
        return;
      }

      this.busy = true;
      this._setUiBusy(true);
      this.$progress.prop('hidden', false);
      this._setProgress(0);
      this._setStatus(Craft.t('polymedia', 'Uploading…'));

      var context = { title: this.$title.val() || '' };
      var destination = this.destination;

      ['folderId', 'siteId', 'fieldId', 'elementId'].forEach(function (key) {
        if (destination[key]) {
          context[key] = destination[key];
        }
      });

      var task = Craft.PolymediaVideoUpload(
        file,
        context,
        function (percent) {
          if (self.uploadTask !== task) {
            return;
          }

          self._setProgress(percent);

          if (percent >= 100) {
            self._setStatus(Craft.t('polymedia', 'Processing video…'));
          }
        }
      );

      this.uploadTask = task;
      task.promise
        .then(function (data) {
          if (self.uploadTask !== task) {
            return;
          }

          return self._complete(data);
        })
        .catch(function (error) {
          if (self.uploadTask !== task) {
            return;
          }

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

    _complete: async function (data) {
      if (!await this.destination.finish(data.assetId)) {
        this.busy = false;
        this._setUiBusy(false);
        this._setStatus(Craft.t('polymedia', 'Media created, but not selected.'));
        return;
      }

      Craft.cp.displayNotice(data.message || Craft.t('polymedia', 'Video upload complete.'));
      this.busy = false;
      this.hide();
    },

    _fail: function (message) {
      this.busy = false;
      this._abortUpload();
      this._setUiBusy(false);
      this._setStatus(message);
      Craft.cp.displayError(message);
    },

    onCancel: function () {
      if (this.busy) {
        // Stops the transfer only; a video the provider created stays there.
        this._abortUpload();
        this.busy = false;
        this._setUiBusy(false);
        this._setStatus(Craft.t('polymedia', 'Upload cancelled.'));
        Craft.cp.displayNotice(Craft.t('polymedia', 'Upload cancelled.'));
      }

      this.hide();
    },

    onFadeOut: function () {
      this._abortUpload();
      this.base();
    },

    _abortUpload: function () {
      if (this.uploadTask && typeof this.uploadTask.abort === 'function') {
        try {
          this.uploadTask.abort();
        } catch (e) {
          // ignore
        }
      }

      this.uploadTask = null;
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
