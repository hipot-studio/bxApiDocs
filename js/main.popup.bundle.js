/* eslint-disable */
this.BX = this.BX || {};
(function (exports, main_core, ui_designTokens, ui_fonts_opensans, main_core_events, ui_a11y, main_core_zIndexManager) {
	'use strict';

	class PositionEvent extends main_core_events.BaseEvent {
		#left;
		#top;
		get left() {
			return this.#left;
		}
		set left(value) {
			if (main_core.Type.isNumber(value)) {
				this.#left = value;
			}
		}
		get top() {
			return this.#top;
		}
		set top(value) {
			if (main_core.Type.isNumber(value)) {
				this.#top = value;
			}
		}
	}

	/**
	 * @namespace {BX.Main.Popup}
	 */
	const CloseIconSize = Object.freeze({
		LARGE: 'large',
		SMALL: 'small'
	});

	/**
	 * @memberOf BX.Main.Popup
	 * @deprecated use BX.UI.Button
	 */
	let Button = /*#__PURE__*/function () {
		function Button(params) {
			babelHelpers.classCallCheck(this, Button);
			this.popupWindow = null;
			this.params = params || {};
			this.text = this.params.text || '';
			this.id = this.params.id || '';
			this.className = this.params.className || '';
			this.events = this.params.events || {};
			this.contextEvents = {};
			for (let eventName in this.events) {
				if (main_core.Type.isFunction(this.events[eventName])) {
					this.contextEvents[eventName] = this.events[eventName].bind(this);
				}
			}
			this.buttonNode = main_core.Dom.create('button', {
				props: {
					className: 'popup-window-button' + (this.className.length > 0 ? ' ' + this.className : ''),
					id: this.id
				},
				attrs: {
					tabindex: '0',
					type: 'button'
				},
				events: this.contextEvents,
				text: this.text
			});
		}
		return babelHelpers.createClass(Button, [{
			key: "render",
			value: function render() {
				return this.buttonNode;
			}
		}, {
			key: "getId",
			value: function getId() {
				return this.id;
			}
		}, {
			key: "getContainer",
			value: function getContainer() {
				return this.buttonNode;
			}
		}, {
			key: "getName",
			value: function getName() {
				return this.text;
			}
		}, {
			key: "setName",
			value: function setName(name) {
				this.text = name || '';
				if (this.buttonNode) {
					main_core.Dom.clean(this.buttonNode);
					main_core.Dom.adjust(this.buttonNode, {
						text: this.text
					});
				}
			}
		}, {
			key: "setClassName",
			value: function setClassName(className) {
				if (this.buttonNode) {
					if (main_core.Type.isString(this.className) && this.className !== '') {
						main_core.Dom.removeClass(this.buttonNode, this.className);
					}
					main_core.Dom.addClass(this.buttonNode, className);
				}
				this.className = className;
			}
		}, {
			key: "addClassName",
			value: function addClassName(className) {
				if (this.buttonNode) {
					main_core.Dom.addClass(this.buttonNode, className);
					this.className = this.buttonNode.className;
				}
			}
		}, {
			key: "removeClassName",
			value: function removeClassName(className) {
				if (this.buttonNode) {
					main_core.Dom.removeClass(this.buttonNode, className);
					this.className = this.buttonNode.className;
				}
			}
		}]);
	}();

	var _Popup;
	function _callSuper$1(t, o, e) { return o = babelHelpers.getPrototypeOf(o), babelHelpers.possibleConstructorReturn(t, _isNativeReflectConstruct$1() ? Reflect.construct(o, [], babelHelpers.getPrototypeOf(t).constructor) : o.apply(t, e)); }
	function _isNativeReflectConstruct$1() { try { var t = !Boolean.prototype.valueOf.call(Reflect.construct(Boolean, [], function () {})); } catch (t) {} return (_isNativeReflectConstruct$1 = function () { return !!t; })(); }
	function _superPropGet(t, o, e, r) { var p = babelHelpers.get(babelHelpers.getPrototypeOf(t.prototype ), o, e); return "function" == typeof p ? function (t) { return p.apply(e, t); } : p; }
	function _classPrivateMethodInitSpec$1(e, a) { _checkPrivateRedeclaration$1(e, a), a.add(e); }
	function _classPrivateFieldInitSpec$1(e, t, a) { _checkPrivateRedeclaration$1(e, t), t.set(e, a); }
	function _checkPrivateRedeclaration$1(e, t) { if (t.has(e)) throw new TypeError("Cannot initialize the same private elements twice on an object"); }
	function _classPrivateFieldGet$1(s, a) { return s.get(_assertClassBrand$1(s, a)); }
	function _classPrivateFieldSet$1(s, a, r) { return s.set(_assertClassBrand$1(s, a), r), r; }
	function _assertClassBrand$1(e, t, n) { if ("function" == typeof e ? e === t : e.has(t)) return arguments.length < 3 ? t : n; throw new TypeError("Private element is not present on this object"); }
	const aliases$1 = {
		onPopupWindowInit: {
			namespace: 'BX.Main.Popup',
			eventName: 'onInit'
		},
		onPopupWindowIsInitialized: {
			namespace: 'BX.Main.Popup',
			eventName: 'onAfterInit'
		},
		onPopupFirstShow: {
			namespace: 'BX.Main.Popup',
			eventName: 'onFirstShow'
		},
		onPopupShow: {
			namespace: 'BX.Main.Popup',
			eventName: 'onShow'
		},
		onAfterPopupShow: {
			namespace: 'BX.Main.Popup',
			eventName: 'onAfterShow'
		},
		onPopupClose: {
			namespace: 'BX.Main.Popup',
			eventName: 'onClose'
		},
		onPopupAfterClose: {
			namespace: 'BX.Main.Popup',
			eventName: 'onAfterClose'
		},
		onPopupDestroy: {
			namespace: 'BX.Main.Popup',
			eventName: 'onDestroy'
		},
		onPopupFullscreenLeave: {
			namespace: 'BX.Main.Popup',
			eventName: 'onFullscreenLeave'
		},
		onPopupFullscreenEnter: {
			namespace: 'BX.Main.Popup',
			eventName: 'onFullscreenEnter'
		},
		onPopupDragStart: {
			namespace: 'BX.Main.Popup',
			eventName: 'onDragStart'
		},
		onPopupDrag: {
			namespace: 'BX.Main.Popup',
			eventName: 'onDrag'
		},
		onPopupDragEnd: {
			namespace: 'BX.Main.Popup',
			eventName: 'onDragEnd'
		},
		onPopupResizeStart: {
			namespace: 'BX.Main.Popup',
			eventName: 'onResizeStart'
		},
		onPopupResize: {
			namespace: 'BX.Main.Popup',
			eventName: 'onResize'
		},
		onPopupResizeEnd: {
			namespace: 'BX.Main.Popup',
			eventName: 'onResizeEnd'
		}
	};
	main_core_events.EventEmitter.registerAliases(aliases$1);
	const disabledScrolls = new WeakMap();

	/**
	 * @memberof BX.Main
	 */
	var _focusTrap = /*#__PURE__*/new WeakMap();
	var _Popup_brand = /*#__PURE__*/new WeakSet();
	let Popup = /*#__PURE__*/function (_EventEmitter) {
		function Popup(_options) {
			var _this;
			babelHelpers.classCallCheck(this, Popup);
			_this = _callSuper$1(this, Popup);
			_classPrivateMethodInitSpec$1(_this, _Popup_brand);
			_classPrivateFieldInitSpec$1(_this, _focusTrap, null);
			/**
			 * @private
			 */
			babelHelpers.defineProperty(_this, "handleAutoHide", event => {
				if (_this.isDestroyed()) {
					return;
				}
				if (_this.autoHideHandler !== null) {
					if (_this.autoHideHandler(event)) {
						_this._tryCloseByEvent(event);
					}
				} else if (event.target !== _this.getPopupContainer() && !_this.getPopupContainer().contains(event.target)) {
					_this._tryCloseByEvent(event);
				}
			});
			/**
			 * @private
			 */
			babelHelpers.defineProperty(_this, "handleDocumentKeyUp", event => {
				if (event.keyCode === 27 && !_this.isDestroyed()) {
					checkEscPressed(_this.getZindex(), () => {
						_this.close();
					});
				}
			});
			_this.setEventNamespace('BX.Main.Popup');
			let [popupId, bindElement, params] = arguments; // compatible arguments

			_this.compatibleMode = params && main_core.Type.isBoolean(params.compatibleMode) ? params.compatibleMode : true;
			if (main_core.Type.isPlainObject(_options) && !bindElement && !params) {
				params = _options;
				popupId = _options.id;
				bindElement = _options.bindElement;
				_this.compatibleMode = false;
			}
			params = params || {};
			_this.params = params;
			if (!main_core.Type.isStringFilled(popupId)) {
				popupId = `popup-window-${main_core.Text.getRandom().toLowerCase()}`;
			}
			_this.emit('onInit', new main_core_events.BaseEvent({
				compatData: [popupId, bindElement, params]
			}));

			/**
			 * @private
			 */
			_this.uniquePopupId = popupId;
			_this.params.zIndex = main_core.Type.isNumber(params.zIndex) ? parseInt(params.zIndex) : 0;
			_this.params.zIndexAbsolute = main_core.Type.isNumber(params.zIndexAbsolute) ? parseInt(params.zIndexAbsolute) : 0;
			_this.buttons = params.buttons && main_core.Type.isArray(params.buttons) ? params.buttons : [];
			_this.offsetTop = Popup.getOption('offsetTop');
			_this.offsetLeft = Popup.getOption('offsetLeft');
			_this.firstShow = false;
			_this.bordersWidth = 20;
			_this.bindElementPos = null;
			_this.closeIcon = null;
			_this.resizeIcon = null;
			_this.angle = null;
			_this.angleArrowElement = null;
			_this.overlay = null;
			_this.titleBar = null;
			_this.bindOptions = main_core.Type.isObject(params.bindOptions) ? params.bindOptions : {};
			_this.autoHide = params.autoHide === true;
			_this.disableScroll = params.disableScroll === true || params.isScrollBlock === true;
			_this.autoHideHandler = main_core.Type.isFunction(params.autoHideHandler) ? params.autoHideHandler : null;
			_this.isAutoHideBinded = false;
			_this.closeByEsc = params.closeByEsc === true;
			_this.isCloseByEscBinded = false;
			_this.toFrontOnShow = true;
			_this.cacheable = true;
			_this.destroyed = false;
			_this.fixed = false;
			_this.width = null;
			_this.height = null;
			_this.minWidth = null;
			_this.minHeight = null;
			_this.maxWidth = null;
			_this.maxHeight = null;
			_this.padding = null;
			_this.contentPadding = null;
			_this.background = null;
			_this.contentBackground = null;
			_this.borderRadius = null;
			_this.contentBorderRadius = null;
			_this.setTargetContainer(params.targetContainer);
			_this.dragOptions = {
				cursor: '',
				callback: function () {},
				eventName: ''
			};
			_this.dragged = false;
			_this.dragPageX = 0;
			_this.dragPageY = 0;
			_this.animationShowClassName = null;
			_this.animationCloseClassName = null;
			_this.animationCloseEventType = null;
			_this.handleDocumentMouseMove = _this.handleDocumentMouseMove.bind(_this);
			_this.handleDocumentMouseUp = _this.handleDocumentMouseUp.bind(_this);
			_this.handleResizeWindow = _this.handleResizeWindow.bind(_this);
			_this.handleResize = _this.handleResize.bind(_this);
			_this.handleMove = _this.handleMove.bind(_this);
			_this.onTitleMouseDown = _this.onTitleMouseDown.bind(_this);
			_this.handleFullScreen = _this.handleFullScreen.bind(_this);
			_this.subscribeFromOptions(params.events);
			let popupClassName = 'popup-window';
			if (params.titleBar) {
				popupClassName += ' popup-window-with-titlebar';
			}
			if (params.className && main_core.Type.isStringFilled(params.className)) {
				popupClassName += ` ${params.className}`;
			}
			if (params.darkMode) {
				popupClassName += ' popup-window-dark';
			}
			_this.designSystemContext = params.darkMode ? '--ui-context-content-dark' : '--ui-context-content-light';
			popupClassName += ` ${_this.designSystemContext}`;
			const titleBarId = `popup-window-titlebar-${popupId}`;
			if (params.titleBar) {
				_this.titleBar = main_core.Tag.render`<div class="popup-window-titlebar" id="${titleBarId}"></div>`;
			}
			if (params.closeIcon) {
				let className = `popup-window-close-icon${params.titleBar ? ' popup-window-titlebar-close-icon' : ''}`;
				if (Object.values(CloseIconSize).includes(params.closeIconSize) && params.closeIconSize !== CloseIconSize.SMALL) {
					className += ` --${params.closeIconSize}`;
				}
				_this.closeIcon = main_core.Tag.render`
				<button 
					tabindex="0" 
					type="button" 
					aria-label="Close" 
					class="${className}" 
					onclick="${_this.handleCloseIconClick.bind(_this)}"
				>
					<span class="ui-icon-set --cross-l --hoverable-default" style="--ui-icon-set__icon-size: 24px;"></span>
				</button>
			`;
				if (main_core.Type.isPlainObject(params.closeIcon)) {
					main_core.Dom.style(_this.closeIcon, params.closeIcon);
				}
			}

			/**
			 * @private
			 */
			_this.contentContainer = main_core.Tag.render`
			<div id="popup-window-content-${popupId}" role="presentation" class="popup-window-content"></div>
		`;

			/**
			 * @private
			 */
			_this.popupContainer = main_core.Tag.render`
			<div
				class="${popupClassName}"
				id="${popupId}"
				style="display: none; position: absolute; left: 0; top: 0;"
				tabindex="-1"
				role="${main_core.Type.isStringFilled(params.role) ? params.role : 'dialog'}"
			>${[_this.titleBar, _this.contentContainer, _this.closeIcon]}</div>
		`;
			if (main_core.Type.isStringFilled(params.ariaLabel)) {
				main_core.Dom.attr(_this.popupContainer, 'aria-label', params.ariaLabel);
			}
			if (main_core.Type.isStringFilled(params.ariaLabelledBy)) {
				main_core.Dom.attr(_this.popupContainer, 'aria-labelledby', params.ariaLabelledBy);
			}
			_this.getTargetContainer().append(_this.popupContainer);
			_this.zIndexComponent = main_core_zIndexManager.ZIndexManager.register(_this.popupContainer, params.zIndexOptions);
			_this.buttonsContainer = null;
			if (params.contentColor && main_core.Type.isStringFilled(params.contentColor)) {
				_this.setContentColor(params.contentColor);
			}
			if (params.angle) {
				_this.setAngle(params.angle);
			}
			if (params.overlay) {
				_this.setOverlay(params.overlay);
			}
			_this.setOffset(params);
			_this.setBindElement(bindElement);
			_this.setTitleBar(params.titleBar);
			_this.setDraggable(params.draggable);
			_this.setContent(params.content);
			_this.setButtons(params.buttons);
			_this.setWidth(params.width);
			_this.setHeight(params.height);
			_this.setMinWidth(params.minWidth);
			_this.setMinHeight(params.minHeight);
			_this.setMaxWidth(params.maxWidth);
			_this.setMaxHeight(params.maxHeight);
			_this.setResizeMode(params.resizable);
			_this.setPadding(params.padding);
			_this.setContentPadding(params.contentPadding);
			_this.setBorderRadius(params.borderRadius);
			_this.setContentBorderRadius(params.contentBorderRadius);
			_this.setBackground(params.background);
			_this.setContentBackground(params.contentBackground);
			_this.setAnimation(params.animation);
			_this.setCacheable(params.cacheable);
			_this.setToFrontOnShow(params.toFrontOnShow);
			_this.setFixed(params.fixed);
			_this.setDesignSystemContext(params.designSystemContext);

			// Compatibility
			if (params.contentNoPaddings) {
				_this.setContentPadding(0);
			}
			if (params.noAllPaddings) {
				_this.setPadding(0);
				_this.setContentPadding(0);
			}
			if (params.bindOnResize !== false) {
				main_core.Event.bind(window, 'resize', _this.handleResizeWindow);
			}
			_assertClassBrand$1(_Popup_brand, _this, _initFocusTrap).call(_this, params.focusTrap);
			_this.emit('onAfterInit', new main_core_events.BaseEvent({
				compatData: [popupId, _this]
			}));
			return _this;
		}

		/**
		 * @private
		 */
		babelHelpers.inherits(Popup, _EventEmitter);
		return babelHelpers.createClass(Popup, [{
			key: "subscribeFromOptions",
			value: function subscribeFromOptions(events) {
				_superPropGet(Popup, "subscribeFromOptions", this)([events, aliases$1]);
			}
		}, {
			key: "getId",
			value: function getId() {
				return this.uniquePopupId;
			}
		}, {
			key: "isCompatibleMode",
			value: function isCompatibleMode() {
				return this.compatibleMode;
			}
		}, {
			key: "setContent",
			value: function setContent(content) {
				if (!this.contentContainer || !content) {
					return;
				}
				if (main_core.Type.isElementNode(content)) {
					main_core.Dom.clean(this.contentContainer);
					const hasParent = main_core.Type.isDomNode(content.parentNode);
					this.contentContainer.appendChild(content);
					if (this.isCompatibleMode() || hasParent) {
						content.style.display = 'block';
					}
				} else if (main_core.Type.isString(content)) {
					this.contentContainer.innerHTML = content;
				} else {
					this.contentContainer.innerHTML = '&nbsp;';
				}
			}
		}, {
			key: "setButtons",
			value: function setButtons(buttons) {
				this.buttons = buttons && main_core.Type.isArray(buttons) ? buttons : [];
				if (this.buttonsContainer) {
					main_core.Dom.remove(this.buttonsContainer);
				}
				const ButtonClass = main_core.Reflection.getClass('BX.UI.Button');
				if (this.buttons.length > 0 && this.contentContainer) {
					const newButtons = [];
					for (let i = 0; i < this.buttons.length; i++) {
						const button = this.buttons[i];
						if (button instanceof Button) {
							button.popupWindow = this;
							newButtons.push(button.render());
						} else if (ButtonClass && button instanceof ButtonClass) {
							button.setContext(this);
							newButtons.push(button.render());
						}
					}
					this.buttonsContainer = main_core.Tag.render`<div class="popup-window-buttons">${newButtons}</div>`;
					this.contentContainer.insertAdjacentElement('afterend', this.buttonsContainer);
				}
			}
		}, {
			key: "getButtons",
			value: function getButtons() {
				return this.buttons;
			}
		}, {
			key: "getButton",
			value: function getButton(id) {
				for (let i = 0; i < this.buttons.length; i++) {
					const button = this.buttons[i];
					if (button.getId() === id) {
						return button;
					}
				}
				return null;
			}
		}, {
			key: "setBindElement",
			value: function setBindElement(bindElement) {
				if (bindElement === null) {
					this.bindElement = null;
				} else if (main_core.Type.isObject(bindElement)) {
					if (main_core.Type.isDomNode(bindElement) || main_core.Type.isNumber(bindElement.top) && main_core.Type.isNumber(bindElement.left)) {
						this.bindElement = bindElement;
					} else if (main_core.Type.isNumber(bindElement.clientX) && main_core.Type.isNumber(bindElement.clientY)) {
						this.bindElement = {
							left: bindElement.pageX,
							top: bindElement.pageY,
							bottom: bindElement.pageY
						};
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "getBindElementPos",
			value: function getBindElementPos(bindElement) {
				if (main_core.Type.isDomNode(bindElement)) {
					if (this.isTargetDocumentBody()) {
						return this.isFixed() ? bindElement.getBoundingClientRect() : main_core.Dom.getPosition(bindElement);
					}
					return this.getPositionRelativeToTarget(bindElement);
				}
				if (bindElement && main_core.Type.isObject(bindElement)) {
					if (!main_core.Type.isNumber(bindElement.bottom)) {
						bindElement.bottom = bindElement.top;
					}
					return bindElement;
				}
				const windowSize = this.getWindowSize();
				const windowScroll = this.getWindowScroll();
				const popupWidth = this.getPopupContainer().offsetWidth;
				const popupHeight = this.getPopupContainer().offsetHeight;
				this.bindOptions.forceTop = true;
				return {
					left: windowSize.innerWidth / 2 - popupWidth / 2 + windowScroll.scrollLeft,
					top: windowSize.innerHeight / 2 - popupHeight / 2 + (this.isFixed() ? 0 : windowScroll.scrollTop),
					bottom: windowSize.innerHeight / 2 - popupHeight / 2 + (this.isFixed() ? 0 : windowScroll.scrollTop),
					// for optimisation purposes
					windowSize,
					windowScroll,
					popupWidth,
					popupHeight
				};
			}

			/**
			 * @internal
			 */
		}, {
			key: "getPositionRelativeToTarget",
			value: function getPositionRelativeToTarget(element) {
				let offsetLeft = element.offsetLeft;
				let offsetTop = element.offsetTop;
				let offsetElement = element.offsetParent;
				while (offsetElement && offsetElement !== this.getTargetContainer()) {
					offsetLeft += offsetElement.offsetLeft;
					offsetTop += offsetElement.offsetTop;
					offsetElement = offsetElement.offsetParent;
				}
				const elementRect = element.getBoundingClientRect();
				return new DOMRect(offsetLeft, offsetTop, elementRect.width, elementRect.height);
			}

			// private
		}, {
			key: "getWindowSize",
			value: function getWindowSize() {
				if (this.isTargetDocumentBody()) {
					return {
						innerWidth: window.innerWidth,
						innerHeight: window.innerHeight
					};
				}
				return {
					innerWidth: this.getTargetContainer().offsetWidth,
					innerHeight: this.getTargetContainer().offsetHeight
				};
			}

			// private
		}, {
			key: "getWindowScroll",
			value: function getWindowScroll() {
				if (this.isTargetDocumentBody()) {
					return {
						scrollLeft: window.pageXOffset,
						scrollTop: window.pageYOffset
					};
				}
				return {
					scrollLeft: this.getTargetContainer().scrollLeft,
					scrollTop: this.getTargetContainer().scrollTop
				};
			}
		}, {
			key: "setAngle",
			value: function setAngle(params) {
				if (params === false) {
					if (this.angle !== null) {
						main_core.Dom.remove(this.angle.element);
					}
					this.angle = null;
					this.angleArrowElement = null;
					return;
				}
				const className = 'popup-window-angly';
				if (this.angle === null) {
					const position = this.bindOptions.position && this.bindOptions.position === 'top' ? 'bottom' : 'top';
					const angleMinLeft = Popup.getOption(position === 'top' ? 'angleMinTop' : 'angleMinBottom');
					let defaultOffset = main_core.Type.isNumber(params.offset) ? params.offset : 0;
					const angleLeftOffset = Popup.getOption('angleLeftOffset', null);
					if (defaultOffset > 0 && main_core.Type.isNumber(angleLeftOffset)) {
						defaultOffset += angleLeftOffset - Popup.defaultOptions.angleLeftOffset;
					}
					this.angleArrowElement = main_core.Tag.render`<div class="popup-window-angly--arrow"></div>`;
					if (this.background) {
						this.angleArrowElement.style.background = this.background;
					}
					this.angle = {
						element: main_core.Tag.render`
					<div class="${className} ${className}-${position}">
						${this.angleArrowElement}
					</div>
				`,
						position,
						offset: 0,
						defaultOffset: Math.max(defaultOffset, angleMinLeft)
						// Math.max(Type.isNumber(params.offset) ? params.offset : 0, angleMinLeft)
					};
					this.getPopupContainer().appendChild(this.angle.element);
				}
				if (main_core.Type.isObject(params) && params.position && ['top', 'right', 'bottom', 'left', 'hide'].includes(params.position)) {
					main_core.Dom.removeClass(this.angle.element, `${className}-${this.angle.position}`);
					main_core.Dom.addClass(this.angle.element, `${className}-${params.position}`);
					this.angle.position = params.position;
				}
				if (main_core.Type.isObject(params) && main_core.Type.isNumber(params.offset)) {
					const offset = params.offset;
					let minOffset, maxOffset;
					if (this.angle.position === 'top') {
						minOffset = Popup.getOption('angleMinTop');
						maxOffset = this.getPopupContainer().offsetWidth - Popup.getOption('angleMaxTop');
						maxOffset = maxOffset < minOffset ? Math.max(minOffset, offset) : maxOffset;
						this.angle.offset = Math.min(Math.max(minOffset, offset), maxOffset);
						this.angle.element.style.left = `${this.angle.offset}px`;
						this.angle.element.style.marginLeft = 0;
						this.angle.element.style.removeProperty('top');
					} else if (this.angle.position === 'bottom') {
						minOffset = Popup.getOption('angleMinBottom');
						maxOffset = this.getPopupContainer().offsetWidth - Popup.getOption('angleMaxBottom');
						maxOffset = maxOffset < minOffset ? Math.max(minOffset, offset) : maxOffset;
						this.angle.offset = Math.min(Math.max(minOffset, offset), maxOffset);
						this.angle.element.style.marginLeft = `${this.angle.offset}px`;
						this.angle.element.style.left = 0;
						this.angle.element.style.removeProperty('top');
					} else if (this.angle.position === 'right') {
						minOffset = Popup.getOption('angleMinRight');
						maxOffset = this.getPopupContainer().offsetHeight - Popup.getOption('angleMaxRight');
						maxOffset = maxOffset < minOffset ? Math.max(minOffset, offset) : maxOffset;
						this.angle.offset = Math.min(Math.max(minOffset, offset), maxOffset);
						this.angle.element.style.top = `${this.angle.offset}px`;
						this.angle.element.style.removeProperty('left');
						this.angle.element.style.removeProperty('margin-left');
					} else if (this.angle.position === 'left') {
						minOffset = Popup.getOption('angleMinLeft');
						maxOffset = this.getPopupContainer().offsetHeight - Popup.getOption('angleMaxLeft');
						maxOffset = maxOffset < minOffset ? Math.max(minOffset, offset) : maxOffset;
						this.angle.offset = Math.min(Math.max(minOffset, offset), maxOffset);
						this.angle.element.style.top = `${this.angle.offset}px`;
						this.angle.element.style.removeProperty('left');
						this.angle.element.style.removeProperty('margin-left');
					}
				}
			}
		}, {
			key: "getWidth",
			value: function getWidth() {
				return this.width;
			}
		}, {
			key: "setWidth",
			value: function setWidth(width) {
				this.setWidthProperty('width', width);
			}
		}, {
			key: "getHeight",
			value: function getHeight() {
				return this.height;
			}
		}, {
			key: "setHeight",
			value: function setHeight(height) {
				this.setHeightProperty('height', height);
			}
		}, {
			key: "getMinWidth",
			value: function getMinWidth() {
				return this.minWidth;
			}
		}, {
			key: "setMinWidth",
			value: function setMinWidth(width) {
				this.setWidthProperty('minWidth', width);
			}
		}, {
			key: "getMinHeight",
			value: function getMinHeight() {
				return this.minHeight;
			}
		}, {
			key: "setMinHeight",
			value: function setMinHeight(height) {
				this.setHeightProperty('minHeight', height);
			}
		}, {
			key: "getMaxWidth",
			value: function getMaxWidth() {
				return this.maxWidth;
			}
		}, {
			key: "setMaxWidth",
			value: function setMaxWidth(width) {
				this.setWidthProperty('maxWidth', width);
			}
		}, {
			key: "getMaxHeight",
			value: function getMaxHeight() {
				return this.maxHeight;
			}
		}, {
			key: "setMaxHeight",
			value: function setMaxHeight(height) {
				this.setHeightProperty('maxHeight', height);
			}

			/**
			 * @private
			 */
		}, {
			key: "setWidthProperty",
			value: function setWidthProperty(property, width) {
				const props = ['width', 'minWidth', 'maxWidth'];
				if (!props.includes(property)) {
					return;
				}
				if (main_core.Type.isNumber(width) && width >= 0) {
					this[property] = width;
					this.getResizableContainer().style[property] = `${width}px`;
					this.getContentContainer().style.overflowX = 'auto';
					this.getPopupContainer().classList.add('popup-window-fixed-width');
				} else if (width === null || width === false) {
					this[property] = null;
					this.getResizableContainer().style.removeProperty(main_core.Text.toKebabCase(property));
					const hasOtherProps = props.some(prop => {
						return this.getResizableContainer().style.getPropertyValue(main_core.Text.toKebabCase(prop)) !== '';
					});
					if (!hasOtherProps) {
						this.getContentContainer().style.removeProperty('overflow-x');
						this.getPopupContainer().classList.remove('popup-window-fixed-width');
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "setHeightProperty",
			value: function setHeightProperty(property, height) {
				const props = ['height', 'minHeight', 'maxHeight'];
				if (!props.includes(property)) {
					return;
				}
				if (main_core.Type.isNumber(height) && height >= 0) {
					this[property] = height;
					this.getResizableContainer().style[property] = `${height}px`;
					this.getContentContainer().style.overflowY = 'auto';
					this.getPopupContainer().classList.add('popup-window-fixed-height');
				} else if (height === null || height === false) {
					this[property] = null;
					this.getResizableContainer().style.removeProperty(main_core.Text.toKebabCase(property));
					const hasOtherProps = props.some(prop => {
						return this.getResizableContainer().style.getPropertyValue(main_core.Text.toKebabCase(prop)) !== '';
					});
					if (!hasOtherProps) {
						this.getContentContainer().style.removeProperty('overflow-y');
						this.getPopupContainer().classList.remove('popup-window-fixed-height');
					}
				}
			}
		}, {
			key: "setPadding",
			value: function setPadding(padding) {
				if (main_core.Type.isNumber(padding) && padding >= 0) {
					this.padding = padding;
					this.getPopupContainer().style.padding = `${padding}px`;
				} else if (padding === null) {
					this.padding = null;
					this.getPopupContainer().style.removeProperty('padding');
				}
			}
		}, {
			key: "getPadding",
			value: function getPadding() {
				return this.padding;
			}
		}, {
			key: "setContentPadding",
			value: function setContentPadding(padding) {
				if (main_core.Type.isNumber(padding) && padding >= 0) {
					this.contentPadding = padding;
					this.getContentContainer().style.padding = `${padding}px`;
				} else if (padding === null) {
					this.contentPadding = null;
					this.getContentContainer().style.removeProperty('padding');
				}
			}
		}, {
			key: "getContentPadding",
			value: function getContentPadding() {
				return this.contentPadding;
			}
		}, {
			key: "setBorderRadius",
			value: function setBorderRadius(radius) {
				if (main_core.Type.isStringFilled(radius)) {
					this.borderRadius = radius;
					this.getPopupContainer().style.setProperty('--popup-window-border-radius', radius);
				} else if (radius === null) {
					this.borderRadius = null;
					this.getPopupContainer().style.removeProperty('--popup-window-border-radius');
				}
			}
		}, {
			key: "setContentBorderRadius",
			value: function setContentBorderRadius(radius) {
				if (main_core.Type.isStringFilled(radius)) {
					this.contentBorderRadius = radius;
					this.getContentContainer().style.setProperty('--popup-window-content-border-radius', radius);
				} else if (radius === null) {
					this.contentBorderRadius = null;
					this.getContentContainer().style.removeProperty('--popup-window-content-border-radius');
				}
			}
		}, {
			key: "setContentColor",
			value: function setContentColor(color) {
				if (main_core.Type.isString(color) && this.contentContainer) {
					this.contentContainer.style.backgroundColor = color;
				} else if (color === null) {
					this.contentContainer.style.style.removeProperty('background-color');
				}
			}
		}, {
			key: "setBackground",
			value: function setBackground(background) {
				if (main_core.Type.isStringFilled(background)) {
					this.background = background;
					this.getPopupContainer().style.background = background;
					if (this.angleArrowElement) {
						this.angleArrowElement.style.background = background;
					}
				} else if (background === null) {
					this.background = null;
					this.getPopupContainer().style.removeProperty('background');
					if (this.angleArrowElement) {
						this.angleArrowElement.style.removeProperty('background');
					}
				}
			}
		}, {
			key: "getBackground",
			value: function getBackground() {
				return this.background;
			}
		}, {
			key: "setContentBackground",
			value: function setContentBackground(background) {
				if (main_core.Type.isStringFilled(background)) {
					this.contentBackground = background;
					this.getContentContainer().style.background = background;
				} else if (background === null) {
					this.contentBackground = null;
					this.getContentContainer().style.removeProperty('background');
				}
			}
		}, {
			key: "getContentBackground",
			value: function getContentBackground() {
				return this.contentBackground;
			}
		}, {
			key: "isDestroyed",
			value: function isDestroyed() {
				return this.destroyed;
			}
		}, {
			key: "setCacheable",
			value: function setCacheable(cacheable) {
				this.cacheable = cacheable !== false;
			}
		}, {
			key: "isCacheable",
			value: function isCacheable() {
				return this.cacheable;
			}
		}, {
			key: "getFocusTrap",
			value: function getFocusTrap() {
				return _classPrivateFieldGet$1(_focusTrap, this);
			}
		}, {
			key: "setToFrontOnShow",
			value: function setToFrontOnShow(flag) {
				this.toFrontOnShow = flag !== false;
			}
		}, {
			key: "shouldFrontOnShow",
			value: function shouldFrontOnShow() {
				return this.toFrontOnShow;
			}
		}, {
			key: "setFixed",
			value: function setFixed(flag) {
				if (main_core.Type.isBoolean(flag)) {
					this.fixed = flag;
					if (flag) {
						main_core.Dom.addClass(this.getPopupContainer(), '--fixed');
					} else {
						main_core.Dom.removeClass(this.getPopupContainer(), '--fixed');
					}
				}
			}
		}, {
			key: "isFixed",
			value: function isFixed() {
				return this.fixed;
			}
		}, {
			key: "setResizeMode",
			value: function setResizeMode(mode) {
				if (mode === true || main_core.Type.isPlainObject(mode)) {
					if (!this.resizeIcon) {
						this.resizeIcon = main_core.Tag.render`
					<div class="popup-window-resize" onmousedown="${this.handleResizeMouseDown.bind(this)}"></div>
				`;
						this.getPopupContainer().appendChild(this.resizeIcon);
					}

					// Compatibility
					this.setMinWidth(mode.minWidth);
					this.setMinHeight(mode.minHeight);
				} else if (mode === false && this.resizeIcon) {
					main_core.Dom.remove(this.resizeIcon);
					this.resizeIcon = null;
				}
			}
		}, {
			key: "getDesignSystemContext",
			value: function getDesignSystemContext() {
				return this.designSystemContext;
			}
		}, {
			key: "setDesignSystemContext",
			value: function setDesignSystemContext(context) {
				if (main_core.Type.isString(context)) {
					if (this.popupContainer !== null) {
						main_core.Dom.removeClass(this.popupContainer, this.designSystemContext);
						main_core.Dom.addClass(this.popupContainer, context);
					}
					this.designSystemContext = context;
				}
			}
		}, {
			key: "setTargetContainer",
			value: function setTargetContainer(targetContainer) {
				const newTargetContainer = main_core.Type.isElementNode(targetContainer) ? targetContainer : document.body;
				if (newTargetContainer === this.targetContainer) {
					return;
				}
				this.targetContainer = newTargetContainer;
				if (this.getPopupContainer()) {
					main_core_zIndexManager.ZIndexManager.unregister(this.getPopupContainer());
					this.getTargetContainer().append(this.getPopupContainer());
					main_core_zIndexManager.ZIndexManager.register(this.getPopupContainer());
				}
				if (this.overlay) {
					main_core.Dom.append(this.overlay.element, this.getTargetContainer());
				}
			}
		}, {
			key: "getTargetContainer",
			value: function getTargetContainer() {
				return this.targetContainer;
			}
		}, {
			key: "isTargetDocumentBody",
			value: function isTargetDocumentBody() {
				return this.getTargetContainer() === document.body;
			}
		}, {
			key: "getPopupContainer",
			value: function getPopupContainer() {
				return this.popupContainer;
			}
		}, {
			key: "getContentContainer",
			value: function getContentContainer() {
				return this.contentContainer;
			}
		}, {
			key: "getResizableContainer",
			value: function getResizableContainer() {
				return this.getPopupContainer();
			}
		}, {
			key: "getTitleContainer",
			value: function getTitleContainer() {
				return this.titleBar;
			}

			/**
			 * @private
			 */
		}, {
			key: "onTitleMouseDown",
			value: function onTitleMouseDown(event) {
				_assertClassBrand$1(_Popup_brand, this, _startDrag).call(this, event, {
					cursor: 'move',
					callback: this.handleMove,
					eventName: 'Drag'
				});
			}

			/**
			 * @private
			 */
		}, {
			key: "handleResizeMouseDown",
			value: function handleResizeMouseDown(event) {
				_assertClassBrand$1(_Popup_brand, this, _startDrag).call(this, event, {
					cursor: 'nwse-resize',
					eventName: 'Resize',
					callback: this.handleResize
				});
				if (this.isTargetDocumentBody()) {
					this.resizeContentPos = main_core.Dom.getPosition(this.getResizableContainer());
					this.resizeContentOffset = this.resizeContentPos.left - main_core.Dom.getPosition(this.getPopupContainer()).left;
				} else {
					this.resizeContentPos = this.getPositionRelativeToTarget(this.getResizableContainer());
					this.resizeContentOffset = this.resizeContentPos.left - this.getPositionRelativeToTarget(this.getPopupContainer()).left;
				}
				this.resizeContentPos.offsetX = 0;
				this.resizeContentPos.offsetY = 0;
			}

			/**
			 * @private
			 */
		}, {
			key: "handleResize",
			value: function handleResize(offsetX, offsetY, pageX, pageY) {
				this.resizeContentPos.offsetX += offsetX;
				this.resizeContentPos.offsetY += offsetY;
				let width = this.resizeContentPos.width + this.resizeContentPos.offsetX;
				let height = this.resizeContentPos.height + this.resizeContentPos.offsetY;
				const scrollWidth = this.isTargetDocumentBody() ? document.documentElement.scrollWidth : this.getTargetContainer().scrollWidth;
				if (this.resizeContentPos.left + width + this.resizeContentOffset >= scrollWidth) {
					width = scrollWidth - this.resizeContentPos.left - this.resizeContentOffset;
				}
				width = Math.max(width, this.getMinWidth());
				height = Math.max(height, this.getMinHeight());
				if (this.getMaxWidth() !== null) {
					width = Math.min(width, this.getMaxWidth());
				}
				if (this.getMaxHeight() !== null) {
					height = Math.min(height, this.getMaxHeight());
				}
				this.setWidth(width);
				this.setHeight(height);
			}
		}, {
			key: "isTopAngle",
			value: function isTopAngle() {
				return this.angle !== null && this.angle.position === 'top';
			}
		}, {
			key: "isBottomAngle",
			value: function isBottomAngle() {
				return this.angle !== null && this.angle.position === 'bottom';
			}
		}, {
			key: "isTopOrBottomAngle",
			value: function isTopOrBottomAngle() {
				return this.angle !== null && (this.angle.position === 'top' || this.angle.position === 'bottom');
			}

			/**
			 * @private
			 */
		}, {
			key: "getAngleHeight",
			value: function getAngleHeight() {
				return this.isTopOrBottomAngle() ? Popup.getOption('angleTopOffset') : 0;
			}
		}, {
			key: "setOffset",
			value: function setOffset(params) {
				if (!main_core.Type.isPlainObject(params)) {
					return;
				}
				if (main_core.Type.isNumber(params.offsetLeft)) {
					this.offsetLeft = params.offsetLeft + Popup.getOption('offsetLeft');
				}
				if (main_core.Type.isNumber(params.offsetTop)) {
					this.offsetTop = params.offsetTop + Popup.getOption('offsetTop');
				}
			}
		}, {
			key: "setTitleBar",
			value: function setTitleBar(params) {
				if (!this.titleBar) {
					return;
				}
				if (main_core.Type.isObject(params) && main_core.Type.isDomNode(params.content)) {
					this.titleBar.innerHTML = '';
					this.titleBar.appendChild(params.content);
				} else if (main_core.Type.isString(params)) {
					this.titleBar.innerHTML = '';
					this.titleBar.appendChild(main_core.Dom.create('span', {
						props: {
							id: `popup-window-titlebar-text-${this.getId()}`,
							className: 'popup-window-titlebar-text'
						},
						text: params
					}));
					if (!main_core.Type.isStringFilled(main_core.Dom.attr(this.getPopupContainer(), 'aria-label'))) {
						main_core.Dom.attr(this.getPopupContainer(), 'aria-label', params);
					}
				}
			}
		}, {
			key: "setDraggable",
			value: function setDraggable(draggable) {
				this.params.draggable = draggable;
				const element = draggable?.element ?? this.titleBar;
				if (!draggable || !element) {
					return;
				}
				main_core.Dom.style(element, 'cursor', 'move');
				main_core.Event.bind(element, 'mousedown', this.onTitleMouseDown);
			}
		}, {
			key: "setClosingByEsc",
			value: function setClosingByEsc(enable) {
				enable = main_core.Type.isBoolean(enable) ? enable : true;
				if (enable) {
					this.closeByEsc = true;
					this.bindClosingByEsc();
				} else {
					this.closeByEsc = false;
					this.unbindClosingByEsc();
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "bindClosingByEsc",
			value: function bindClosingByEsc() {
				if (this.closeByEsc && !this.isCloseByEscBinded) {
					main_core.Event.bind(this.targetContainer.ownerDocument, 'keyup', this.handleDocumentKeyUp, true);
					this.isCloseByEscBinded = true;
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "unbindClosingByEsc",
			value: function unbindClosingByEsc() {
				if (this.isCloseByEscBinded) {
					main_core.Event.unbind(this.targetContainer.ownerDocument, 'keyup', this.handleDocumentKeyUp, true);
					this.isCloseByEscBinded = false;
				}
			}
		}, {
			key: "setAutoHide",
			value: function setAutoHide(enable) {
				enable = main_core.Type.isBoolean(enable) ? enable : true;
				if (enable) {
					this.autoHide = true;
					this.bindAutoHide();
				} else {
					this.autoHide = false;
					this.unbindAutoHide();
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "bindAutoHide",
			value: function bindAutoHide() {
				if (this.autoHide && !this.isAutoHideBinded && this.isShown()) {
					this.isAutoHideBinded = true;
					if (this.isCompatibleMode()) {
						main_core.Event.bind(this.getPopupContainer(), 'click', this.handleContainerClick);
					}
					if (!this.hasOverlay()) {
						main_core.Event.bind(this.targetContainer.ownerDocument, 'click', this.handleAutoHide, !this.isCompatibleMode());
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "unbindAutoHide",
			value: function unbindAutoHide() {
				if (this.isAutoHideBinded) {
					this.isAutoHideBinded = false;
					if (this.isCompatibleMode()) {
						main_core.Event.unbind(this.getPopupContainer(), 'click', this.handleContainerClick);
					}
					if (!this.hasOverlay()) {
						main_core.Event.unbind(this.targetContainer.ownerDocument, 'click', this.handleAutoHide, !this.isCompatibleMode());
					}
				}
			}
		}, {
			key: "_tryCloseByEvent",
			value:
			/**
			 * @private
			 */
			function _tryCloseByEvent(event) {
				if (this.isCompatibleMode()) {
					this.tryCloseByEvent(event);
				} else {
					setTimeout(() => {
						this.tryCloseByEvent(event);
					}, 0);
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "tryCloseByEvent",
			value: function tryCloseByEvent(event) {
				if (event.button === 0) {
					this.close();
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "handleOverlayClick",
			value: function handleOverlayClick(event) {
				if (this.autoHide) {
					this.tryCloseByEvent(event);
					event.stopPropagation();
				}
			}
		}, {
			key: "setOverlay",
			value: function setOverlay(params) {
				if (this.overlay === null) {
					this.unbindAutoHide();
					this.overlay = {
						element: main_core.Tag.render`
					<div
						class="popup-window-overlay"
						id="popup-window-overlay-${this.getId()}"
						onclick="${this.handleOverlayClick.bind(this)}"
						aria-hidden="true"
					></div>
				`
					};
					this.resizeOverlay();
					main_core.Dom.append(this.overlay.element, this.getTargetContainer());
					this.getZIndexComponent().setOverlay(this.overlay.element);
				}
				if (main_core.Type.isNumber(params?.opacity) && params.opacity >= 0 && params.opacity <= 100) {
					main_core.Dom.style(this.overlay.element, 'opacity', parseFloat(params.opacity / 100).toPrecision(3));
				}
				if (params?.backgroundColor) {
					main_core.Dom.style(this.overlay.element, 'background-color', params.backgroundColor);
				}
				if (params?.blur) {
					main_core.Dom.style(this.overlay.element, 'backdrop-filter', params.blur);
				}
			}
		}, {
			key: "isModal",
			value: function isModal() {
				return this.hasOverlay();
			}
		}, {
			key: "hasOverlay",
			value: function hasOverlay() {
				return this.overlay !== null && this.overlay.element !== null;
			}
		}, {
			key: "removeOverlay",
			value: function removeOverlay() {
				if (this.overlay !== null && this.overlay.element !== null) {
					main_core.Dom.remove(this.overlay.element);
					this.getZIndexComponent().setOverlay(null);
				}
				if (this.overlayTimeout) {
					clearInterval(this.overlayTimeout);
					this.overlayTimeout = null;
				}
				this.overlay = null;
			}
		}, {
			key: "hideOverlay",
			value: function hideOverlay() {
				if (this.overlay !== null && this.overlay.element !== null) {
					if (this.overlayTimeout) {
						clearInterval(this.overlayTimeout);
						this.overlayTimeout = null;
					}
					this.overlay.element.style.display = 'none';
				}
			}
		}, {
			key: "showOverlay",
			value: function showOverlay() {
				if (this.overlay !== null && this.overlay.element !== null) {
					this.overlay.element.style.display = 'block';
					let popupHeight = this.getPopupContainer().offsetHeight;
					this.overlayTimeout = setInterval(() => {
						if (popupHeight !== this.getPopupContainer().offsetHeight) {
							this.resizeOverlay();
							popupHeight = this.getPopupContainer().offsetHeight;
						}
					}, 1000);
				}
			}
		}, {
			key: "resizeOverlay",
			value: function resizeOverlay() {
				if (this.overlay !== null && this.overlay.element !== null) {
					let scrollWidth = 0;
					let scrollHeight = 0;
					if (this.isTargetDocumentBody()) {
						scrollWidth = document.documentElement.scrollWidth;
						scrollHeight = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight, document.body.offsetHeight, document.documentElement.offsetHeight, document.body.clientHeight, document.documentElement.clientHeight);
					} else {
						scrollWidth = this.getTargetContainer().scrollWidth;
						scrollHeight = this.getTargetContainer().scrollHeight;
					}
					this.overlay.element.style.width = `${scrollWidth}px`;
					this.overlay.element.style.height = `${scrollHeight}px`;
				}
			}
		}, {
			key: "getZindex",
			value: function getZindex() {
				return this.getZIndexComponent().getZIndex();
			}
		}, {
			key: "getZIndexComponent",
			value: function getZIndexComponent() {
				return this.zIndexComponent;
			}
		}, {
			key: "setDisableScroll",
			value: function setDisableScroll(flag) {
				const disable = main_core.Type.isBoolean(flag) ? flag : true;
				if (disable) {
					this.disableScroll = true;
					_assertClassBrand$1(_Popup_brand, this, _disableTargetScroll).call(this);
				} else {
					this.disableScroll = false;
					_assertClassBrand$1(_Popup_brand, this, _enableTargetScroll).call(this);
				}
			}
		}, {
			key: "show",
			value: function show() {
				if (this.isShown() || this.isDestroyed()) {
					return;
				}
				this.emit('onBeforeShow');
				this.showOverlay();
				this.getPopupContainer().style.display = 'block';
				main_core.Dom.addClass(this.getPopupContainer(), '--open');
				_classPrivateFieldGet$1(_focusTrap, this)?.captureActiveElement();
				if (this.shouldFrontOnShow()) {
					this.bringToFront();
				}
				if (!this.firstShow) {
					this.emit('onFirstShow', new main_core_events.BaseEvent({
						compatData: [this]
					}));
					this.firstShow = true;
				}
				this.emit('onShow', new main_core_events.BaseEvent({
					compatData: [this]
				}));
				if (this.disableScroll) {
					_assertClassBrand$1(_Popup_brand, this, _disableTargetScroll).call(this);
				}
				this.adjustPosition();
				this.animateOpening(() => {
					if (this.isDestroyed()) {
						return;
					}
					main_core.Dom.removeClass(this.getPopupContainer(), this.animationShowClassName);
					this.emit('onAfterShow', new main_core_events.BaseEvent({
						compatData: [this]
					}));
					_classPrivateFieldGet$1(_focusTrap, this)?.activate();
				});
				this.bindClosingByEsc();
				if (this.isCompatibleMode()) {
					setTimeout(() => {
						this.bindAutoHide();
					}, 100);
				} else {
					this.bindAutoHide();
				}
			}
		}, {
			key: "close",
			value: function close() {
				if (this.isDestroyed() || !this.isShown()) {
					return;
				}
				this.emit('onClose', new main_core_events.BaseEvent({
					compatData: [this]
				}));
				if (this.isDestroyed()) {
					return;
				}
				if (this.disableScroll) {
					_assertClassBrand$1(_Popup_brand, this, _enableTargetScroll).call(this);
				}
				_classPrivateFieldGet$1(_focusTrap, this)?.deactivate();
				this.animateClosing(() => {
					if (this.isDestroyed()) {
						return;
					}
					this.hideOverlay();
					this.getPopupContainer().style.display = 'none';
					main_core.Dom.removeClass(this.getPopupContainer(), '--open');
					main_core.Dom.removeClass(this.getPopupContainer(), this.animationCloseClassName);
					this.unbindClosingByEsc();
					if (this.isCompatibleMode()) {
						setTimeout(() => {
							this.unbindAutoHide();
						}, 0);
					} else {
						this.unbindAutoHide();
					}
					this.emit('onAfterClose', new main_core_events.BaseEvent({
						compatData: [this]
					}));
					if (!this.isCacheable()) {
						this.destroy();
					}
				});
			}
		}, {
			key: "bringToFront",
			value: function bringToFront() {
				if (this.isShown()) {
					main_core_zIndexManager.ZIndexManager.bringToFront(this.getPopupContainer());
				}
			}
		}, {
			key: "toggle",
			value: function toggle() {
				if (this.isShown()) {
					this.close();
				} else {
					this.show();
				}
			}

			/**
			 *
			 * @private
			 */
		}, {
			key: "animateOpening",
			value: function animateOpening(callback) {
				main_core.Dom.removeClass(this.getPopupContainer(), this.animationCloseClassName);
				if (this.animationShowClassName === null) {
					callback();
				} else {
					main_core.Dom.addClass(this.getPopupContainer(), this.animationShowClassName);
					if (this.animationCloseEventType === null) {
						callback();
					} else {
						const eventName = `${this.animationCloseEventType}end`;
						const className = this.animationShowClassName;
						this.getPopupContainer().addEventListener(eventName, function handleTransitionEnd(event) {
							if (!main_core.Dom.hasClass(event.target, className)) {
								return;
							}
							this.removeEventListener(eventName, handleTransitionEnd);
							callback();
						});
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "animateClosing",
			value: function animateClosing(callback) {
				main_core.Dom.removeClass(this.getPopupContainer(), this.animationShowClassName);
				if (this.animationCloseClassName === null) {
					callback();
				} else {
					main_core.Dom.addClass(this.getPopupContainer(), this.animationCloseClassName);
					if (this.animationCloseEventType === null) {
						callback();
					} else {
						const eventName = `${this.animationCloseEventType}end`;
						const className = this.animationCloseClassName;
						this.getPopupContainer().addEventListener(eventName, function handleTransitionEnd(event) {
							if (!main_core.Dom.hasClass(event.target, className)) {
								return;
							}
							this.removeEventListener(eventName, handleTransitionEnd);
							callback();
						});
					}
				}
			}
		}, {
			key: "setAnimation",
			value: function setAnimation(options) {
				if (main_core.Type.isPlainObject(options)) {
					this.animationShowClassName = main_core.Type.isStringFilled(options.showClassName) ? options.showClassName : null;
					this.animationCloseClassName = main_core.Type.isStringFilled(options.closeClassName) ? options.closeClassName : null;
					this.animationCloseEventType = options.closeAnimationType === 'animation' || options.closeAnimationType === 'transition' ? options.closeAnimationType : null;
				} else if (main_core.Type.isStringFilled(options)) {
					const animationName = options;
					if (animationName === 'fading') {
						this.animationShowClassName = 'popup-window-show-animation-opacity';
						this.animationCloseClassName = 'popup-window-close-animation-opacity';
						this.animationCloseEventType = 'animation';
					} else if (animationName === 'fading-slide') {
						this.animationShowClassName = 'popup-window-show-animation-opacity-transform';
						this.animationCloseClassName = 'popup-window-close-animation-opacity';
						this.animationCloseEventType = 'animation';
					} else if (animationName === 'scale') {
						this.animationShowClassName = 'popup-window-show-animation-scale';
						this.animationCloseClassName = 'popup-window-close-animation-opacity';
						this.animationCloseEventType = 'animation';
					}
				} else if (options === false || options === null) {
					this.animationShowClassName = null;
					this.animationCloseClassName = null;
					this.animationCloseEventType = null;
				}
			}
		}, {
			key: "isShown",
			value: function isShown() {
				return !this.isDestroyed() && this.getPopupContainer()?.style.display === 'block';
			}
		}, {
			key: "destroy",
			value: function destroy() {
				if (this.destroyed) {
					return;
				}
				if (this.disableScroll) {
					_assertClassBrand$1(_Popup_brand, this, _enableTargetScroll).call(this);
				}
				this.destroyed = true;
				this.emit('onDestroy', new main_core_events.BaseEvent({
					compatData: [this]
				}));
				this.unbindClosingByEsc();
				if (this.isCompatibleMode()) {
					setTimeout(() => {
						this.unbindAutoHide();
					}, 0);
				} else {
					this.unbindAutoHide();
				}
				main_core.Event.unbindAll(this);
				main_core.Event.unbind(document, 'mousemove', this.handleDocumentMouseMove);
				main_core.Event.unbind(document, 'mouseup', this.handleDocumentMouseUp);
				main_core.Event.unbind(window, 'resize', this.handleResizeWindow);
				this.removeOverlay();
				main_core_zIndexManager.ZIndexManager.unregister(this.popupContainer);
				this.zIndexComponent = null;
				_classPrivateFieldGet$1(_focusTrap, this)?.destroy();
				_classPrivateFieldSet$1(_focusTrap, this, null);
				main_core.Dom.remove(this.popupContainer);
				this.popupContainer = null;
				this.contentContainer = null;
				this.closeIcon = null;
				this.titleBar = null;
				this.buttonsContainer = null;
				this.angle = null;
				this.angleArrowElement = null;
				this.resizeIcon = null;
			}
		}, {
			key: "adjustPosition",
			value: function adjustPosition(bindOptions) {
				if (bindOptions && main_core.Type.isObject(bindOptions)) {
					this.bindOptions = bindOptions;
				}
				const bindElementPos = this.getBindElementPos(this.bindElement);
				if (!this.bindOptions.forceBindPosition && this.bindElementPos !== null && bindElementPos.top === this.bindElementPos.top && bindElementPos.left === this.bindElementPos.left) {
					return;
				}
				const bindElementVanished = bindElementPos.top === 0 && bindElementPos.left === 0 && bindElementPos.width === 0 && bindElementPos.height === 0;
				this.bindElementPos = bindElementVanished && this.bindElementPos !== null ? this.bindElementPos : bindElementPos;
				const windowSize = bindElementPos.windowSize ?? this.getWindowSize();
				const windowScroll = bindElementPos.windowScroll ?? this.getWindowScroll();
				const popupWidth = bindElementPos.popupWidth ?? this.popupContainer.offsetWidth;
				const popupHeight = bindElementPos.popupHeight ?? this.popupContainer.offsetHeight;
				const angleTopOffset = Popup.getOption('angleTopOffset');
				let left = this.bindElementPos.left + this.offsetLeft - (this.isTopOrBottomAngle() ? Popup.getOption('angleLeftOffset') : 0);
				if (!this.bindOptions.forceLeft && left + popupWidth + this.bordersWidth >= windowSize.innerWidth + windowScroll.scrollLeft && windowSize.innerWidth + windowScroll.scrollLeft - popupWidth - this.bordersWidth > 0) {
					const bindLeft = left;
					left = windowSize.innerWidth + windowScroll.scrollLeft - popupWidth - this.bordersWidth;
					if (this.isTopOrBottomAngle()) {
						this.setAngle({
							offset: bindLeft - left + this.angle.defaultOffset
						});
					}
				} else if (this.isTopOrBottomAngle()) {
					this.setAngle({
						offset: this.angle.defaultOffset + (left < 0 ? left : 0)
					});
				}
				if (left < 0) {
					left = 0;
				}
				let top = 0;
				if (this.bindOptions.position && this.bindOptions.position === 'top') {
					top = this.bindElementPos.top - popupHeight - this.offsetTop - (this.isBottomAngle() ? angleTopOffset : 0);
					if (top < 0 || !this.bindOptions.forceTop && top < windowScroll.scrollTop) {
						top = this.bindElementPos.bottom + this.offsetTop;
						if (this.angle !== null) {
							top += angleTopOffset;
							this.setAngle({
								position: 'top'
							});
						}
					} else if (this.isTopAngle()) {
						top = top - angleTopOffset + Popup.getOption('positionTopXOffset');
						this.setAngle({
							position: 'bottom'
						});
					} else {
						top += Popup.getOption('positionTopXOffset');
					}
				} else {
					top = this.bindElementPos.bottom + this.offsetTop + this.getAngleHeight();
					if (!this.bindOptions.forceTop && top + popupHeight > windowSize.innerHeight + windowScroll.scrollTop
					// Can we place the PopupWindow above the bindElement?
					&& this.bindElementPos.top - popupHeight - this.getAngleHeight() >= 0) {
						// The PopupWindow doesn't place below the bindElement. We should place it above.
						top = this.bindElementPos.top - popupHeight;
						if (this.isTopOrBottomAngle()) {
							top -= angleTopOffset;
							this.setAngle({
								position: 'bottom'
							});
						}
						top += Popup.getOption('positionTopXOffset');
					} else if (this.isBottomAngle()) {
						top += angleTopOffset;
						this.setAngle({
							position: 'top'
						});
					}
				}
				if (top < 0) {
					top = 0;
				}
				const event = new PositionEvent();
				event.left = left;
				event.top = top;
				this.emit('onBeforeAdjustPosition', event);
				main_core.Dom.adjust(this.popupContainer, {
					style: {
						top: `${event.top}px`,
						left: `${event.left}px`
					}
				});
			}
		}, {
			key: "enterFullScreen",
			value: function enterFullScreen() {
				if (Popup.fullscreenStatus) {
					if (document.cancelFullScreen) {
						document.cancelFullScreen();
					} else if (document.mozCancelFullScreen) {
						document.mozCancelFullScreen();
					} else if (document.webkitCancelFullScreen) {
						document.webkitCancelFullScreen();
					}
				} else {
					if (this.contentContainer.requestFullScreen) {
						this.contentContainer.requestFullScreen();
						main_core.Event.bind(window, 'fullscreenchange', this.handleFullScreen);
					} else if (this.contentContainer.mozRequestFullScreen) {
						this.contentContainer.mozRequestFullScreen();
						main_core.Event.bind(window, 'mozfullscreenchange', this.handleFullScreen);
					} else if (this.contentContainer.webkitRequestFullScreen) {
						this.contentContainer.webkitRequestFullScreen();
						main_core.Event.bind(window, 'webkitfullscreenchange', this.handleFullScreen);
					} else {
						console.log('fullscreen mode is not supported');
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "handleFullScreen",
			value: function handleFullScreen(event) {
				if (Popup.fullscreenStatus) {
					main_core.Event.unbind(window, 'fullscreenchange', this.handleFullScreen);
					main_core.Event.unbind(window, 'webkitfullscreenchange', this.handleFullScreen);
					main_core.Event.unbind(window, 'mozfullscreenchange', this.handleFullScreen);
					Popup.fullscreenStatus = false;
					if (!this.isDestroyed()) {
						main_core.Dom.removeClass(this.contentContainer, 'popup-window-fullscreen');
						this.emit('onFullscreenLeave');
						this.adjustPosition();
					}
				} else {
					Popup.fullscreenStatus = true;
					if (!this.isDestroyed()) {
						main_core.Dom.addClass(this.contentContainer, 'popup-window-fullscreen');
						this.emit('onFullscreenEnter');
						this.adjustPosition();
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "handleCloseIconClick",
			value: function handleCloseIconClick(event) {
				this.tryCloseByEvent(event);
				event.stopPropagation();
			}

			/**
			 * @private
			 */
		}, {
			key: "handleContainerClick",
			value: function handleContainerClick(event) {
				event.stopPropagation();
			}
		}, {
			key: "handleResizeWindow",
			value:
			/**
			 * @private
			 */
			function handleResizeWindow() {
				if (this.isShown()) {
					this.adjustPosition();
					if (this.overlay !== null) {
						this.resizeOverlay();
					}
				}
			}

			/**
			 * @private
			 */
		}, {
			key: "handleMove",
			value: function handleMove(offsetX, offsetY, pageX, pageY) {
				let left = parseInt(this.popupContainer.style.left, 10) + offsetX;
				let top = parseInt(this.popupContainer.style.top, 10) + offsetY;
				if (main_core.Type.isObject(this.params.draggable) && this.params.draggable.restrict) {
					// Left side
					if (left < 0) {
						left = 0;
					}
					let scrollWidth;
					let scrollHeight;
					if (this.isTargetDocumentBody()) {
						scrollWidth = document.documentElement.scrollWidth;
						scrollHeight = document.documentElement.scrollHeight;
					} else {
						scrollWidth = this.getTargetContainer().scrollWidth;
						scrollHeight = this.getTargetContainer().scrollHeight;
					}

					// Right side
					const floatWidth = this.popupContainer.offsetWidth;
					const floatHeight = this.popupContainer.offsetHeight;
					if (left > scrollWidth - floatWidth) {
						left = scrollWidth - floatWidth;
					}
					if (top > scrollHeight - floatHeight) {
						top = scrollHeight - floatHeight;
					}

					// Top side
					if (top < 0) {
						top = 0;
					}
				}
				this.popupContainer.style.left = `${left}px`;
				this.popupContainer.style.top = `${top}px`;
			}

			/**
			 * @private
			 */
		}, {
			key: "handleDocumentMouseMove",
			value:
			/**
			 * @private
			 */
			function handleDocumentMouseMove(event) {
				if (this.dragPageX === event.pageX && this.dragPageY === event.pageY) {
					return;
				}
				this.dragOptions.callback(event.pageX - this.dragPageX, event.pageY - this.dragPageY, event.pageX, event.pageY);
				this.dragPageX = event.pageX;
				this.dragPageY = event.pageY;
				if (!this.dragged) {
					this.emit(`on${this.dragOptions.eventName}Start`, new main_core_events.BaseEvent({
						compatData: [this]
					}));
					this.dragged = true;
				}
				this.emit(`on${this.dragOptions.eventName}`, new main_core_events.BaseEvent({
					compatData: [this]
				}));
			}

			/**
			 * @private
			 */
		}, {
			key: "handleDocumentMouseUp",
			value: function handleDocumentMouseUp(event) {
				if (document.body.releaseCapture) {
					document.body.releaseCapture();
				}
				main_core.Event.unbind(document, 'mousemove', this.handleDocumentMouseMove);
				main_core.Event.unbind(document, 'mouseup', this.handleDocumentMouseUp);
				document.body.ondrag = null;
				document.body.onselectstart = null;
				document.body.style.cursor = '';
				document.body.style.MozUserSelect = '';
				this.popupContainer.style.MozUserSelect = '';
				this.emit(`on${this.dragOptions.eventName}End`, new main_core_events.BaseEvent({
					compatData: [this]
				}));
				this.dragged = false;
				event.preventDefault();
			}
		}], [{
			key: "setOptions",
			value: function setOptions(options) {
				if (!main_core.Type.isPlainObject(options)) {
					return;
				}
				for (let option in options) {
					this.options[option] = options[option];
				}
			}
		}, {
			key: "getOption",
			value: function getOption(option, defaultValue) {
				if (!main_core.Type.isUndefined(this.options[option])) {
					return this.options[option];
				}
				if (!main_core.Type.isUndefined(defaultValue)) {
					return defaultValue;
				}
				return this.defaultOptions[option];
			}
		}, {
			key: "shouldUseFocusTrapByDefault",
			value: function shouldUseFocusTrapByDefault() {
				if (!ui_a11y.AccessibilitySettings.useFocusTrapInDialogs()) {
					return false;
				}
				const activeElement = ui_a11y.FocusNavigator.getActiveElement();
				const nonTextInputTypes = new Set(['checkbox', 'radio', 'range', 'color', 'file', 'image', 'button', 'submit', 'reset']);
				if (activeElement === null) {
					return true;
				}
				const isTextInput = activeElement.tagName === 'TEXTAREA' || activeElement.tagName === 'INPUT' && !nonTextInputTypes.has(activeElement.type) || activeElement.isContentEditable;
				return !isTextInput;
			}
		}]);
	}(main_core_events.EventEmitter);
	_Popup = Popup;
	function _initFocusTrap(options) {
		if (options === false || main_core.Type.isNil(options) && !_Popup.shouldUseFocusTrapByDefault()) {
			return;
		}
		const defaultOptions = {
			initialFocus: ['[data-autofocus]', 'container'],
			isolateOutside: this.isModal()
		};
		const focusTrapOptions = main_core.Type.isPlainObject(options) ? options : {};
		_classPrivateFieldSet$1(_focusTrap, this, new ui_a11y.FocusTrap(this.popupContainer, {
			...defaultOptions,
			...focusTrapOptions
		}));
		if (this.isModal()) {
			main_core.Dom.attr(this.overlay.element, 'data-focus-trap', _classPrivateFieldGet$1(_focusTrap, this).getId());
		}
	}
	function _disableTargetScroll() {
		const target = this.getTargetContainer();
		let popups = disabledScrolls.get(target);
		if (!popups) {
			popups = new Set();
			disabledScrolls.set(target, popups);
		}
		popups.add(this);
		main_core.Dom.addClass(target, 'popup-window-disable-scroll');
	}
	function _enableTargetScroll() {
		const target = this.getTargetContainer();
		const popups = disabledScrolls.get(target) || null;
		if (popups) {
			popups.delete(this);
		}
		if (popups === null || popups.size === 0) {
			main_core.Dom.removeClass(target, 'popup-window-disable-scroll');
		}
	}
	function _startDrag(event, options) {
		options = options || {};
		if (main_core.Type.isStringFilled(options.cursor)) {
			this.dragOptions.cursor = options.cursor;
		}
		if (main_core.Type.isStringFilled(options.eventName)) {
			this.dragOptions.eventName = options.eventName;
		}
		if (main_core.Type.isFunction(options.callback)) {
			this.dragOptions.callback = options.callback;
		}
		this.dragPageX = event.pageX;
		this.dragPageY = event.pageY;
		this.dragged = false;
		main_core.Event.bind(document, 'mousemove', this.handleDocumentMouseMove);
		main_core.Event.bind(document, 'mouseup', this.handleDocumentMouseUp);
		if (document.body.setCapture) {
			document.body.setCapture();
		}
		document.body.ondrag = () => false;
		document.body.onselectstart = () => false;
		document.body.style.cursor = this.dragOptions.cursor;
		document.body.style.MozUserSelect = 'none';
		this.popupContainer.style.MozUserSelect = 'none';
		if (this.shouldFrontOnShow()) {
			this.bringToFront();
		}
		event.preventDefault();
	}
	/**
	 * @private
	 */
	babelHelpers.defineProperty(Popup, "options", {});
	/**
	 * @private
	 */
	babelHelpers.defineProperty(Popup, "defaultOptions", {
		// left offset for popup about target
		angleLeftOffset: 40,
		// when popup position is 'top' offset distance between popup body and target node
		positionTopXOffset: -11,
		// offset distance between popup body and target node if use angle, sum with positionTopXOffset
		angleTopOffset: 10,
		popupZindex: 1000,
		popupOverlayZindex: 1100,
		angleMinLeft: 10,
		angleMaxLeft: 30,
		angleMinRight: 10,
		angleMaxRight: 30,
		angleMinBottom: 23,
		angleMaxBottom: 25,
		angleMinTop: 23,
		angleMaxTop: 25,
		offsetLeft: 0,
		offsetTop: 0
	});
	let escCallbackIndex = -1;
	let escCallback = null;
	function checkEscPressed(zIndex, callback) {
		if (zIndex === false) {
			if (escCallback && escCallback.length > 0) {
				for (let i = 0; i < escCallback.length; i++) {
					escCallback[i]();
				}
				escCallback = null;
				escCallbackIndex = -1;
			}
		} else {
			if (escCallback === null) {
				escCallback = [];
				escCallbackIndex = -1;
				setTimeout(() => {
					checkEscPressed(false);
				}, 10);
			}
			if (zIndex > escCallbackIndex) {
				escCallbackIndex = zIndex;
				escCallback = [callback];
			} else if (zIndex === escCallbackIndex) {
				escCallback.push(callback);
			}
		}
	}

	const aliases = {
		onSubMenuShow: {
			namespace: 'BX.Main.Menu.Item',
			eventName: 'SubMenu:onShow'
		},
		onSubMenuClose: {
			namespace: 'BX.Main.Menu.Item',
			eventName: 'SubMenu:onClose'
		}
	};
	const reEscape = /["'<>]/g;
	const escapeEntities = {
		'<': '&lt;',
		'>': '&gt;',
		"'": '&#39;',
		'"': '&quot;'
	};
	function encodeSafe(value) {
		if (main_core.Type.isString(value)) {
			return value.replaceAll(reEscape, item => escapeEntities[item]);
		}
		return value;
	}
	main_core_events.EventEmitter.registerAliases(aliases);
	class MenuItem extends main_core_events.EventEmitter {
		#items = [];
		#justFocused = false;
		constructor(itemOptions) {
			super();
			this.setEventNamespace('BX.Main.Menu.Item');
			const options = itemOptions || {};
			this.options = options;
			this.id = options.id || main_core.Text.getRandom();
			this.text = '';
			this.allowHtml = false;
			if (main_core.Type.isStringFilled(options.html) || main_core.Type.isElementNode(options.html)) {
				this.text = options.html;
				this.allowHtml = true;
			} else if (main_core.Type.isStringFilled(options.text)) {
				this.text = options.text;
				if (/<[^>]+>/.test(this.text)) {
					// eslint-disable-next-line no-console
					console.warn('BX.Main.MenuItem: use "html" option for the html item content.', this.getText());
				}
			}
			this.title = main_core.Type.isStringFilled(options.title) ? options.title : '';
			this.delimiter = options.delimiter === true;
			this.href = main_core.Type.isStringFilled(options.href) ? options.href : null;
			this.target = main_core.Type.isStringFilled(options.target) ? options.target : null;
			this.dataset = main_core.Type.isPlainObject(options.dataset) ? options.dataset : null;
			this.className = main_core.Type.isStringFilled(options.className) ? options.className : null;
			this.menuShowDelay = main_core.Type.isNumber(options.menuShowDelay) ? options.menuShowDelay : 300;
			this.subMenuOffsetX = main_core.Type.isNumber(options.subMenuOffsetX) ? options.subMenuOffsetX : 4;
			this.#items = main_core.Type.isArray(options.items) ? options.items : [];
			this.disabled = options.disabled === true;
			this.cacheable = options.cacheable === true;
			this.focusable = this.delimiter !== true && options.focusable !== false;
			this.attrs = main_core.Type.isPlainObject(options.attrs) ? options.attrs : null;

			/**
			 *
			 * @type {function|string}
			 */
			this.onclick = main_core.Type.isStringFilled(options.onclick) || main_core.Type.isFunction(options.onclick) ? options.onclick : null;
			this.subscribeFromOptions(options.events, aliases);

			/**
			 *
			 * @type {Menu}
			 */
			this.menuWindow = null;

			/**
			 *
			 * @type {Menu}
			 */
			this.subMenuWindow = null;

			/**
			 *
			 * @type {{item: HTMLElement, text: HTMLElement}}
			 */
			this.layout = {
				item: null,
				text: null
			};
			this.getLayout(); // compatibility

			// compatibility
			// now use this.options
			this.events = {};
			this.items = [];
			for (const property of Object.keys(options)) {
				if (!(property in this)) {
					this[property] = options[property];
				}
			}
		}
		getLayout() {
			if (this.layout.item) {
				return this.layout;
			}
			if (this.delimiter) {
				if (main_core.Type.isStringFilled(this.getText())) {
					this.layout.item = main_core.Dom.create('span', {
						props: {
							className: ['popup-window-delimiter-section', this.className || ''].join(' ')
						},
						attrs: {
							'aria-hidden': 'true'
						},
						children: [this.layout.text = main_core.Tag.render`
							<span class="popup-window-delimiter-text">${this.allowHtml ? this.getText() : encodeSafe(this.getText())}</span>
						`]
					});
				} else {
					this.layout.item = main_core.Tag.render`<span class="popup-window-delimiter" aria-hidden="true"></span>`;
				}
			} else {
				this.layout.item = main_core.Dom.create(this.href ? 'a' : 'span', {
					props: {
						className: ['menu-popup-item', this.className || 'menu-popup-no-icon', this.hasSubMenu() ? 'menu-popup-item-submenu' : ''].join(' ')
					},
					attrs: {
						title: this.title,
						onclick: main_core.Type.isString(this.onclick) ? this.onclick : '',
						// compatibility
						target: this.target || ''
					},
					dataset: this.dataset,
					events: main_core.Type.isFunction(this.onclick) ? {
						click: this.onItemClick.bind(this)
					} : null,
					children: [main_core.Dom.create('span', {
						props: {
							className: 'menu-popup-item-icon'
						}
					}), this.layout.text = main_core.Tag.render`
						<span class="menu-popup-item-text">${this.allowHtml ? this.getText() : encodeSafe(this.getText())}</span>
					`]
				});
				if (main_core.Type.isPlainObject(this.attrs)) {
					main_core.Dom.attr(this.layout.item, this.attrs);
				}
				if (this.isFocusable()) {
					main_core.Dom.attr(this.layout.item, 'tabindex', '-1');
					main_core.Dom.attr(this.layout.item, 'role', 'menuitem');
				} else {
					main_core.Dom.attr(this.layout.item, 'aria-hidden', 'true');
				}
				if (this.hasSubMenu()) {
					main_core.Dom.attr(this.layout.item, 'aria-haspopup', 'true');
					main_core.Dom.attr(this.layout.item, 'aria-expanded', 'false');
				}
				if (this.href) {
					this.layout.item.href = this.href;
				}
				if (this.isDisabled()) {
					this.disable();
				}
				main_core.Event.bind(this.layout.item, ui_a11y.RESTORE_FOCUS_EVENT, this.#handleItemRestoreFocus.bind(this));
			}
			main_core.Event.bind(this.layout.item, 'mouseenter', this.#handleItemMouseEnter.bind(this));
			main_core.Event.bind(this.layout.item, 'mouseleave', this.#handleItemMouseLeave.bind(this));
			main_core.Event.bind(this.layout.item, 'focusin', this.#handleItemFocus.bind(this));
			return this.layout;
		}
		getContainer() {
			return this.getLayout().item;
		}
		getTextContainer() {
			return this.getLayout().text;
		}
		getText() {
			return this.text;
		}
		getTextContent() {
			if (main_core.Type.isString(this.text)) {
				return this.text;
			}
			if (main_core.Type.isElementNode(this.text)) {
				return this.text.textContent || '';
			}
			return '';
		}
		setText(text, allowHtml = false) {
			if (main_core.Type.isString(text) || main_core.Type.isElementNode(text)) {
				this.allowHtml = allowHtml;
				this.text = text;
				if (main_core.Type.isElementNode(text)) {
					main_core.Dom.clean(this.getTextContainer());
					if (this.allowHtml) {
						main_core.Dom.append(text, this.getTextContainer());
					} else {
						this.getTextContainer().innerHTML = encodeSafe(text.outerHTML);
					}
				} else {
					this.getTextContainer().innerHTML = this.allowHtml ? text : encodeSafe(text);
				}
			}
		}
		hasSubMenu() {
			return this.subMenuWindow !== null || this.#items.length > 0;
		}
		showSubMenu(trigger = null) {
			if (!this.getMenuWindow().isShown()) {
				return;
			}
			this.addSubMenu(this.#items);
			if (this.getSubMenu() !== null) {
				this.closeSiblings(trigger);
				this.closeChildren(trigger);
				if (!this.getSubMenu().isShown()) {
					this.emit('SubMenu:onShow');
					this.getSubMenu().setLastInputModality(trigger);
					this.getSubMenu().show();
				}
				main_core.Dom.attr(this.layout.item, 'aria-controls', this.getSubMenu().getId());
				this.adjustSubMenu();
			}
		}
		addSubMenu(items) {
			if (this.subMenuWindow !== null || !main_core.Type.isArray(items) || items.length === 0) {
				return null;
			}
			const rootMenuWindow = this.getMenuWindow().getRootMenuWindow() || this.getMenuWindow();
			const rootOptions = {
				...rootMenuWindow.params
			};
			delete rootOptions.events;
			const subMenuOptions = main_core.Type.isPlainObject(rootMenuWindow.params.subMenuOptions) ? rootMenuWindow.params.subMenuOptions : {};
			const options = {
				...rootOptions,
				...subMenuOptions
			};

			// Override root menu options
			options.autoHide = false;
			options.menuShowDelay = this.menuShowDelay;
			options.cacheable = this.isCacheable();
			options.targetContainer = this.getMenuWindow().getPopupWindow().getTargetContainer();
			options.bindOptions = {
				forceTop: true,
				forceLeft: true,
				forceBindPosition: true
			};
			options.focusTrap = {
				initialFocus: false
			};
			delete options.angle;
			delete options.overlay;
			this.subMenuWindow = new Menu(`popup-submenu-${this.id}`, this.layout.item, items, options);
			this.subMenuWindow.setParentMenuWindow(this.getMenuWindow());
			this.subMenuWindow.setParentMenuItem(this);
			this.subMenuWindow.subscribe('onShow', this.#handleSubMenuShow.bind(this));
			this.subMenuWindow.subscribe('onClose', this.#handleSubMenuClose.bind(this));
			this.subMenuWindow.subscribe('onDestroy', this.#handleSubMenuDestroy.bind(this));
			main_core.Dom.addClass(this.layout.item, 'menu-popup-item-submenu');
			main_core.Dom.attr(this.layout.item, 'aria-haspopup', 'true');
			return this.subMenuWindow;
		}
		closeSubMenu(trigger = null) {
			this.#clearSubMenuTimeout();
			if (this.getSubMenu() !== null) {
				this.closeChildren(trigger);
				if (this.getSubMenu().isShown()) {
					this.emit('SubMenu:onClose');
				}
				this.getSubMenu().setLastInputModality(trigger);
				this.getSubMenu().close();
			}
		}
		closeSiblings(trigger = null) {
			const siblings = this.menuWindow.getMenuItems();
			for (const sibling of siblings) {
				if (sibling !== this) {
					sibling.closeSubMenu(trigger);
				}
			}
		}
		closeChildren(trigger = null) {
			if (this.getSubMenu() !== null) {
				const children = this.getSubMenu().getMenuItems();
				for (const child of children) {
					child.closeSubMenu(trigger);
				}
			}
		}
		destroySubMenu() {
			if (this.getSubMenu() !== null) {
				main_core.Dom.removeClass(this.layout.item, 'menu-popup-item-open menu-popup-item-submenu');
				main_core.Dom.attr(this.layout.item, 'aria-haspopup', null);
				this.destroyChildren();
				this.getSubMenu().destroy();
				this.subMenuWindow = null;
				this.#items = [];
			}
		}
		destroyChildren() {
			if (this.getSubMenu() !== null) {
				const children = this.getSubMenu().getMenuItems();
				for (const child of children) {
					child.destroySubMenu();
				}
			}
		}
		adjustSubMenu() {
			if (this.getSubMenu() === null || !this.layout.item) {
				return;
			}
			const popupWindow = this.getSubMenu().getPopupWindow();
			const itemRect = this.getBoundingClientRect();
			let offsetLeft = itemRect.width + this.subMenuOffsetX;
			let offsetTop = itemRect.height + this.getPopupPadding();
			let angleOffset = itemRect.height / 2 - this.getPopupPadding();
			let anglePosition = 'left';
			const popupWidth = popupWindow.getPopupContainer().offsetWidth;
			const popupHeight = popupWindow.getPopupContainer().offsetHeight;
			const popupBottom = itemRect.top + popupHeight;
			const targetContainer = this.getMenuWindow().getPopupWindow().getTargetContainer();
			const isGlobalContext = this.getMenuWindow().getPopupWindow().isTargetDocumentBody();
			const clientWidth = isGlobalContext ? document.documentElement.clientWidth : targetContainer.offsetWidth;
			const clientHeight = isGlobalContext ? document.documentElement.clientHeight : targetContainer.offsetHeight;

			// let's try to fit a submenu to the browser viewport
			const exceeded = popupBottom - clientHeight;
			if (exceeded > 0) {
				let roundOffset = Math.ceil(exceeded / itemRect.height) * itemRect.height;
				if (roundOffset > itemRect.top) {
					// it cannot be higher than the browser viewport.
					roundOffset -= Math.ceil((roundOffset - itemRect.top) / itemRect.height) * itemRect.height;
				}
				if (itemRect.bottom > popupBottom - roundOffset) {
					// let's sync bottom boundaries.
					roundOffset -= itemRect.bottom - (popupBottom - roundOffset) + this.getPopupPadding();
				}
				offsetTop += roundOffset;
				angleOffset += roundOffset;
			}
			if (itemRect.left + offsetLeft + popupWidth > clientWidth) {
				const left = itemRect.left - popupWidth - this.subMenuOffsetX;
				if (left > 0) {
					offsetLeft = -popupWidth - this.subMenuOffsetX;
					anglePosition = 'right';
				}
			}
			popupWindow.setBindElement(this.layout.item);
			popupWindow.setOffset({
				offsetLeft,
				offsetTop: -offsetTop
			});
			popupWindow.setAngle({
				position: anglePosition,
				offset: angleOffset
			});
			popupWindow.adjustPosition();
		}
		getBoundingClientRect() {
			const popup = this.getMenuWindow().getPopupWindow();
			if (popup.isTargetDocumentBody()) {
				return this.layout.item.getBoundingClientRect();
			}
			const rect = popup.getPositionRelativeToTarget(this.layout.item);
			const targetContainer = this.getMenuWindow().getPopupWindow().getTargetContainer();
			return new DOMRect(rect.left - targetContainer.scrollLeft, rect.top - targetContainer.scrollTop, rect.width, rect.height);
		}
		getPopupPadding() {
			if (!main_core.Type.isNumber(this.popupPadding)) {
				if (this.subMenuWindow) {
					const menuContainer = this.subMenuWindow.layout.menuContainer;
					this.popupPadding = parseInt(main_core.Dom.style(menuContainer, 'paddingTop'), 10);
				} else {
					this.popupPadding = 0;
				}
			}
			return this.popupPadding;
		}
		getSubMenu() {
			return this.subMenuWindow;
		}
		getId() {
			return this.id;
		}
		setMenuWindow(menu) {
			this.menuWindow = menu;
		}
		getMenuWindow() {
			return this.menuWindow;
		}
		getMenuShowDelay() {
			return this.menuShowDelay;
		}
		enable() {
			this.disabled = false;
			main_core.Dom.removeClass(this.getContainer(), 'menu-popup-item-disabled');
			main_core.Dom.attr(this.getContainer(), 'aria-disabled', null);
		}
		disable() {
			this.disabled = true;
			this.closeSubMenu();
			main_core.Dom.addClass(this.getContainer(), 'menu-popup-item-disabled');
			main_core.Dom.attr(this.getContainer(), 'aria-disabled', 'true');
		}
		isDisabled() {
			return this.disabled;
		}
		isFocusable() {
			return this.focusable;
		}
		setCacheable(cacheable) {
			this.cacheable = cacheable !== false;
		}
		isCacheable() {
			return this.cacheable;
		}
		isDelimiter() {
			return this.delimiter;
		}
		focus(focusVisible = false) {
			if (this.isFocused() || this.isDelimiter() || !this.isFocusable()) {
				return;
			}
			this.focused = true;
			main_core.Dom.addClass(this.getContainer(), `--focus${focusVisible ? ' --focus-visible' : ''}`);
			main_core.Dom.attr(this.getContainer(), 'tabindex', '0');
			if (this.getMenuWindow().getFocusTrap() !== null) {
				this.getContainer().focus({
					preventScroll: !focusVisible
				});
			}
			this.getMenuWindow().emit('Item:onFocus', {
				item: this
			});
		}
		blur() {
			if (!this.isFocused() || this.isDelimiter() || !this.isFocusable()) {
				return;
			}
			this.focused = false;
			main_core.Dom.removeClass(this.getContainer(), '--focus --focus-visible');
			main_core.Dom.attr(this.getContainer(), 'tabindex', '-1');
			this.getMenuWindow().emit('Item:onBlur', {
				item: this
			});
		}
		isFocused() {
			return this.focused;
		}

		/**
		 * @private
		 */
		onItemClick(event) {
			this.onclick.call(this.menuWindow, event, this); // compatibility
		}
		#handleItemFocus() {
			this.#justFocused = true;
			setTimeout(() => {
				this.#justFocused = false;
			}, 100);
		}
		#handleItemMouseEnter(mouseEvent) {
			if (this.getMenuWindow()?.shouldIgnoreMouseEnter() || this.#justFocused) {
				return;
			}
			const event = new main_core_events.BaseEvent({
				data: {
					mouseEvent
				}
			});
			main_core_events.EventEmitter.emit(this, 'onMouseEnter', event, {
				thisArg: this
			});
			if (event.isDefaultPrevented()) {
				return;
			}
			this.focus();
			this.#clearSubMenuTimeout();
			if (this.hasSubMenu()) {
				this.subMenuTimeout = setTimeout(() => {
					this.showSubMenu('pointer');
				}, this.menuShowDelay);
			} else {
				this.subMenuTimeout = setTimeout(() => {
					this.closeSiblings('pointer');
				}, this.menuShowDelay);
			}
		}
		#handleItemMouseLeave(mouseEvent) {
			const event = new main_core_events.BaseEvent({
				data: {
					mouseEvent
				}
			});
			main_core_events.EventEmitter.emit(this, 'onMouseLeave', event, {
				thisArg: this
			});
			if (event.isDefaultPrevented()) {
				return;
			}
			this.blur();
			this.#clearSubMenuTimeout();
		}
		#handleItemRestoreFocus(event) {
			event.preventDefault();
			const hasFocus = this.getMenuWindow().getMenuItems().some(item => item.isFocused());
			if (!hasFocus) {
				this.focus(ui_a11y.FocusMonitor.Instance.getLastInputModality() === 'keyboard');
			}
		}
		#clearSubMenuTimeout() {
			if (this.subMenuTimeout) {
				clearTimeout(this.subMenuTimeout);
			}
			this.subMenuTimeout = null;
		}
		#handleSubMenuShow() {
			main_core.Dom.addClass(this.layout.item, 'menu-popup-item-open');
			main_core.Dom.attr(this.layout.item, 'aria-expanded', 'true');
		}
		#handleSubMenuClose() {
			main_core.Dom.removeClass(this.layout.item, 'menu-popup-item-open');
			main_core.Dom.attr(this.layout.item, 'aria-expanded', 'false');
			main_core.Dom.attr(this.layout.item, 'aria-controls', null);
		}
		#handleSubMenuDestroy() {
			this.#handleSubMenuClose();
			this.subMenuWindow = null;
		}
	}

	const collator = new Intl.Collator(undefined, {
		sensitivity: 'base'
	});
	class MenuNavigation {
		#menu = null;
		#enabled = false;
		#onKeyDownHandler = null;
		#searchBuffer = '';
		#resetTimer = null;
		#onTab = null;
		#initialFocusPosition = 'first';
		constructor(menu, options = {}) {
			this.#menu = menu;
			this.#menu.subscribe('onShow', this.#handleMenuShow.bind(this));
			this.#menu.subscribe('onDestroy', this.#handleMenuDestroy.bind(this));
			this.#onKeyDownHandler = this.#handleKeyDown.bind(this);
			this.#onTab = main_core.Type.isFunction(options.onTab) ? options.onTab : null;
			this.setInitialFocusPosition(options.initialFocusPosition);
		}
		getMenu() {
			return this.#menu;
		}
		enable() {
			if (!this.isEnabled()) {
				this.bindEvents();
			}
			this.#enabled = true;
		}
		disable() {
			if (this.isEnabled()) {
				this.unbindEvents();
			}
			this.#enabled = false;
		}
		isEnabled() {
			return this.#enabled;
		}
		bindEvents() {
			main_core.Event.bind(this.getMenu().getMenuContainer(), 'keydown', this.#onKeyDownHandler);
		}
		unbindEvents() {
			main_core.Event.unbind(this.getMenu().getMenuContainer(), 'keydown', this.#onKeyDownHandler);
		}
		setInitialFocusPosition(focusPosition) {
			if (focusPosition === 'first' || focusPosition === 'last') {
				this.#initialFocusPosition = focusPosition;
			}
		}
		#handleMenuShow() {
			this.enable();
			if (this.#menu.getParentMenuWindow() === null) {
				if (ui_a11y.FocusMonitor.Instance.getLastInputModality() === 'keyboard' && this.#menu.getFocusTrap() !== null) {
					if (this.#initialFocusPosition === 'last') {
						this.focusLast();
					} else {
						this.focusFirst();
					}
				}
			} else if (this.#menu.getLastInputModal() === 'keyboard') {
				this.focusFirst();
			}
		}
		#handleMenuDestroy() {
			this.disable();
			this.#menu = null;
		}
		#handleKeyDown(event) {
			if (!this.getMenu().isShown()) {
				return;
			}
			if (event.metaKey || event.ctrlKey || event.altKey) {
				return;
			}
			const target = event.target;
			if (target?.tagName === 'TEXTAREA' || target instanceof HTMLInputElement) {
				return;
			}
			event.preventDefault();
			switch (event.key) {
				case 'ArrowDown':
					{
						this.focusNext();
						break;
					}
				case 'ArrowUp':
					{
						this.focusPrevious();
						break;
					}
				case 'ArrowLeft':
					{
						const focusedItem = this.getMenu().getFocusedItem();
						const parentItem = focusedItem?.getMenuWindow()?.getParentMenuItem();
						if (parentItem) {
							parentItem.focus(true);
							parentItem.closeSubMenu('keyboard');
						}
						break;
					}
				case 'ArrowRight':
					{
						this.#openSubMenuByKeyboard(this.getMenu().getFocusedItem());
						break;
					}
				case 'Home':
				case 'PageUp':
					{
						this.focusFirst();
						break;
					}
				case 'End':
				case 'PageDown':
					{
						this.focusLast();
						break;
					}
				case ' ':
				case 'Enter':
				case 'Space':
					{
						const focusedItem = this.getMenu().getFocusedItem();
						if (focusedItem) {
							if (focusedItem.hasSubMenu()) {
								this.#openSubMenuByKeyboard(focusedItem);
							} else {
								focusedItem.getContainer().click();
							}
						}
						break;
					}
				case 'Tab':
					{
						if (this.#onTab === null) {
							const rootMenuWindow = this.getMenu().getRootMenuWindow() || this.getMenu();
							rootMenuWindow.close();
						} else {
							this.#onTab(event, this.#menu);
						}
						break;
					}
				default:
					{
						if (this.#isTypeaheadEvent(event)) {
							this.#searchBuffer += event.key;
							this.focusByText(this.#searchBuffer);
							clearTimeout(this.#resetTimer);
							this.#resetTimer = setTimeout(() => {
								this.#searchBuffer = '';
							}, 200);
						}
						break;
					}
			}
		}
		#openSubMenuByKeyboard(item) {
			if (!item || !item.hasSubMenu()) {
				return;
			}
			if (item.getSubMenu()?.isShown()) {
				item.getSubMenu().getNavigation().focusFirst();
			} else {
				item.showSubMenu('keyboard');
			}
			item.blur();
		}
		#isTypeaheadEvent(event) {
			if (event.key === ' ' && this.#searchBuffer.length > 0) {
				return true;
			}
			return event.key.length === 1 && /^[\p{Letter}\p{Number}]$/u.test(event.key);
		}
		focusByText(text) {
			const items = this.getItems();
			const focusedItem = this.getMenu().getFocusedItem();
			const normalizedText = text.toLowerCase();
			const matches = items.filter(item => {
				const word = item.getTextContent().trim().toLowerCase().slice(0, Math.max(0, normalizedText.length));
				return collator.compare(normalizedText, word) === 0;
			});
			if (matches.length === 0) {
				return null;
			}
			const focusedMatchIndex = matches.indexOf(focusedItem);
			if (focusedMatchIndex >= 0) {
				const item = matches[(focusedMatchIndex + 1) % matches.length];
				item.focus(true);
				return item;
			}
			const focusedIndex = items.indexOf(focusedItem);
			const nextMatch = matches.find(item => items.indexOf(item) > focusedIndex) ?? matches[0];
			nextMatch.focus(true);
			return nextMatch;
		}
		focusNext(looped = true) {
			const focusedItem = this.getMenu().getFocusedItem();
			const items = this.getItems();
			if (items.length === 0) {
				return null;
			}
			if (!focusedItem) {
				items[0].focus(true);
				return items[0];
			}
			const position = this.getMenuItemPosition(focusedItem.id);
			if (position === -1) {
				items[0].focus(true);
				return items[0];
			}
			if (position + 1 < items.length) {
				const nextPosition = position + 1;
				items[nextPosition].focus(true);
				return items[nextPosition];
			}
			if (looped) {
				items[0].focus(true);
				return items[0];
			}
			return null;
		}
		focusPrevious(looped = true) {
			const focusedItem = this.getMenu().getFocusedItem();
			const items = this.getItems();
			if (items.length === 0) {
				return null;
			}
			if (!focusedItem) {
				items[items.length - 1].focus(true);
				return items[items.length - 1];
			}
			const position = this.getMenuItemPosition(focusedItem.id);
			if (position === -1) {
				items[items.length - 1].focus(true);
				return items[items.length - 1];
			}
			if (position - 1 >= 0) {
				const previousPosition = position - 1;
				items[previousPosition].focus(true);
				return items[previousPosition];
			}
			if (looped) {
				const lastPosition = items.length - 1;
				items[lastPosition].focus(true);
				return items[lastPosition];
			}
			return null;
		}
		focusFirst() {
			const items = this.getItems();
			if (items.length > 0) {
				items[0].focus(true);
				return items[0];
			}
			return null;
		}
		focusLast() {
			const items = this.getItems();
			if (items.length > 0) {
				items[items.length - 1].focus(true);
				return items[items.length - 1];
			}
			return null;
		}
		getItems() {
			return this.getMenu().getMenuItems().filter(item => {
				return item.isFocusable() && ui_a11y.InteractivityChecker.isVisible(item.getContainer());
			});
		}
		getMenuItemPosition(itemId) {
			const items = this.getItems();
			for (const [i, item] of items.entries()) {
				if (item.id && item.id === itemId) {
					return i;
				}
			}
			return -1;
		}
	}

	function _callSuper(t, o, e) { return o = babelHelpers.getPrototypeOf(o), babelHelpers.possibleConstructorReturn(t, _isNativeReflectConstruct() ? Reflect.construct(o, [], babelHelpers.getPrototypeOf(t).constructor) : o.apply(t, e)); }
	function _isNativeReflectConstruct() { try { var t = !Boolean.prototype.valueOf.call(Reflect.construct(Boolean, [], function () {})); } catch (t) {} return (_isNativeReflectConstruct = function () { return !!t; })(); }
	function _classPrivateMethodInitSpec(e, a) { _checkPrivateRedeclaration(e, a), a.add(e); }
	function _classPrivateFieldInitSpec(e, t, a) { _checkPrivateRedeclaration(e, t), t.set(e, a); }
	function _checkPrivateRedeclaration(e, t) { if (t.has(e)) throw new TypeError("Cannot initialize the same private elements twice on an object"); }
	function _classPrivateFieldGet(s, a) { return s.get(_assertClassBrand(s, a)); }
	function _classPrivateFieldSet(s, a, r) { return s.set(_assertClassBrand(s, a), r), r; }
	function _assertClassBrand(e, t, n) { if ("function" == typeof e ? e === t : e.has(t)) return arguments.length < 3 ? t : n; throw new TypeError("Private element is not present on this object"); }
	/**
	 * @memberof BX.Main
	 */
	var _navigation = /*#__PURE__*/new WeakMap();
	var _focusedItem = /*#__PURE__*/new WeakMap();
	var _lastInputModality = /*#__PURE__*/new WeakMap();
	var _ignoreMouseEnter = /*#__PURE__*/new WeakMap();
	var _Menu_brand = /*#__PURE__*/new WeakSet();
	let Menu = /*#__PURE__*/function (_EventEmitter) {
		function Menu(_options) {
			var _this;
			babelHelpers.classCallCheck(this, Menu);
			_this = _callSuper(this, Menu);
			/**
			 * @private
			 */
			_classPrivateMethodInitSpec(_this, _Menu_brand);
			_classPrivateFieldInitSpec(_this, _navigation, null);
			_classPrivateFieldInitSpec(_this, _focusedItem, null);
			_classPrivateFieldInitSpec(_this, _lastInputModality, null);
			_classPrivateFieldInitSpec(_this, _ignoreMouseEnter, true);
			_this.setEventNamespace('BX.Main.Menu');
			let [_id, bindElement, menuItems, params] = arguments;
			if (main_core.Type.isPlainObject(_options) && !bindElement && !menuItems && !params) {
				params = _options;
				params.compatibleMode = false;
				_id = _options.id;
				bindElement = _options.bindElement;
				menuItems = _options.items;
				if (!main_core.Type.isStringFilled(_id)) {
					_id = `menu-popup-${main_core.Text.getRandom()}`;
				}
			}
			_this.emit('onInit', {
				id: _id,
				bindElement,
				menuItems,
				params
			});
			_this.id = _id;
			_this.bindElement = bindElement;

			/**
			 *
			 * @type {MenuItem[]}
			 */
			_this.menuItems = [];
			_this.itemsContainer = null;
			_this.params = params && typeof params === 'object' ? params : {};
			_this.parentMenuWindow = null;
			_this.parentMenuItem = null;
			if (menuItems && main_core.Type.isArray(menuItems)) {
				for (const menuItem of menuItems) {
					_this.addMenuItemInternal(menuItem, null);
				}
			}
			_this.layout = {
				menuContainer: null,
				itemsContainer: null
			};
			_this.popupWindow = _assertClassBrand(_Menu_brand, _this, _createPopup).call(_this);
			_classPrivateFieldSet(_navigation, _this, new MenuNavigation(_this, params?.navigationOptions));
			_this.subscribe('Item:onFocus', _assertClassBrand(_Menu_brand, _this, _handleItemFocus).bind(_this));
			_this.subscribe('Item:onBlur', _assertClassBrand(_Menu_brand, _this, _handleItemBlur).bind(_this));
			return _this;
		}
		babelHelpers.inherits(Menu, _EventEmitter);
		return babelHelpers.createClass(Menu, [{
			key: "getPopupWindow",
			value: function getPopupWindow() {
				return this.popupWindow;
			}
		}, {
			key: "show",
			value: function show() {
				this.getPopupWindow().show();
			}
		}, {
			key: "close",
			value: function close() {
				this.getPopupWindow().close();
			}
		}, {
			key: "destroy",
			value: function destroy() {
				this.getPopupWindow().destroy();
			}
		}, {
			key: "toggle",
			value: function toggle() {
				if (this.getPopupWindow().isShown()) {
					this.close();
				} else {
					this.show();
				}
			}
		}, {
			key: "isShown",
			value: function isShown() {
				return this.getPopupWindow().isShown();
			}
		}, {
			key: "getId",
			value: function getId() {
				return this.id;
			}
		}, {
			key: "getNavigation",
			value: function getNavigation() {
				return _classPrivateFieldGet(_navigation, this);
			}
		}, {
			key: "getFocusTrap",
			value: function getFocusTrap() {
				return this.getPopupWindow().getFocusTrap();
			}
		}, {
			key: "setLastInputModality",
			value: function setLastInputModality(modality) {
				_classPrivateFieldSet(_lastInputModality, this, modality);
			}
		}, {
			key: "getLastInputModal",
			value: function getLastInputModal() {
				return _classPrivateFieldGet(_lastInputModality, this);
			}
		}, {
			key: "shouldIgnoreMouseEnter",
			value: function shouldIgnoreMouseEnter() {
				return _classPrivateFieldGet(_ignoreMouseEnter, this);
			}
		}, {
			key: "containsTarget",
			value:
			/**
			 * @private
			 */
			function containsTarget(target) {
				const el = this.getPopupWindow().getPopupContainer();
				if (this.getPopupWindow().isShown() && (target === el || el.contains(target))) {
					return true;
				}
				return this.getMenuItems().some(item => {
					return item.getSubMenu() && item.getSubMenu().containsTarget(target);
				});
			}
		}, {
			key: "setParentMenuWindow",
			value: function setParentMenuWindow(parentMenu) {
				if (parentMenu instanceof Menu) {
					this.parentMenuWindow = parentMenu;
				}
			}
		}, {
			key: "getParentMenuWindow",
			value: function getParentMenuWindow() {
				return this.parentMenuWindow;
			}
		}, {
			key: "isRootMenu",
			value: function isRootMenu() {
				return this.getParentMenuWindow() === null;
			}
		}, {
			key: "getRootMenuWindow",
			value: function getRootMenuWindow() {
				let root = null;
				let parent = this.getParentMenuWindow();
				while (parent !== null) {
					root = parent;
					parent = parent.getParentMenuWindow();
				}
				return root;
			}
		}, {
			key: "setParentMenuItem",
			value: function setParentMenuItem(parentItem) {
				if (parentItem instanceof MenuItem) {
					this.parentMenuItem = parentItem;
				}
			}
		}, {
			key: "getParentMenuItem",
			value: function getParentMenuItem() {
				return this.parentMenuItem;
			}
		}, {
			key: "addMenuItem",
			value: function addMenuItem(menuItemJson, targetItemId) {
				const menuItem = this.addMenuItemInternal(menuItemJson, targetItemId);
				if (!menuItem) {
					return null;
				}
				const itemLayout = menuItem.getLayout();
				const targetItem = this.getMenuItem(targetItemId);
				if (targetItem === null) {
					this.itemsContainer.appendChild(itemLayout.item);
				} else {
					const targetLayout = targetItem.getLayout();
					this.itemsContainer.insertBefore(itemLayout.item, targetLayout.item);
				}
				return menuItem;
			}

			/**
			 * @private
			 */
		}, {
			key: "addMenuItemInternal",
			value: function addMenuItemInternal(menuItemJson, targetItemId) {
				if (!menuItemJson || !menuItemJson.delimiter && !main_core.Type.isStringFilled(menuItemJson.text) && !main_core.Type.isStringFilled(menuItemJson.html) && !main_core.Type.isElementNode(menuItemJson.html) || menuItemJson.id && this.getMenuItem(menuItemJson.id) !== null) {
					return null;
				}
				if (main_core.Type.isNumber(this.params.menuShowDelay)) {
					menuItemJson.menuShowDelay = this.params.menuShowDelay;
				}
				const menuItem = new MenuItem(menuItemJson);
				menuItem.setMenuWindow(this);
				const position = this.getMenuItemPosition(targetItemId);
				if (position >= 0) {
					this.menuItems.splice(position, 0, menuItem);
				} else {
					this.menuItems.push(menuItem);
				}
				return menuItem;
			}
		}, {
			key: "removeMenuItem",
			value: function removeMenuItem(itemId, options = {
				destroyEmptyPopup: true
			}) {
				const item = this.getMenuItem(itemId);
				if (!item) {
					return;
				}
				for (let position = 0; position < this.menuItems.length; position++) {
					if (this.menuItems[position] === item) {
						item.destroySubMenu();
						this.menuItems.splice(position, 1);
						break;
					}
				}
				if (this.menuItems.length === 0) {
					const menuWindow = item.getMenuWindow();
					if (menuWindow) {
						const parentMenuItem = menuWindow.getParentMenuItem();
						if (parentMenuItem) {
							parentMenuItem.destroySubMenu();
						} else if (options.destroyEmptyPopup) {
							menuWindow.destroy();
						}
					}
				}
				item.layout.item.parentNode.removeChild(item.layout.item);
				item.layout = {
					item: null,
					text: null
				};
			}
		}, {
			key: "getMenuItem",
			value: function getMenuItem(itemId) {
				for (let i = 0; i < this.menuItems.length; i++) {
					if (this.menuItems[i].id && this.menuItems[i].id === itemId) {
						return this.menuItems[i];
					}
				}
				return null;
			}
		}, {
			key: "getMenuItems",
			value: function getMenuItems() {
				return this.menuItems;
			}
		}, {
			key: "getMenuItemPosition",
			value: function getMenuItemPosition(itemId) {
				if (itemId) {
					for (let i = 0; i < this.menuItems.length; i++) {
						if (this.menuItems[i].id && this.menuItems[i].id === itemId) {
							return i;
						}
					}
				}
				return -1;
			}
		}, {
			key: "getMenuContainer",
			value: function getMenuContainer() {
				return this.getPopupWindow().getPopupContainer();
			}
		}, {
			key: "getFocusedItem",
			value: function getFocusedItem() {
				return _classPrivateFieldGet(_focusedItem, this);
			}
		}, {
			key: "clearFocus",
			value: function clearFocus() {
				if (_classPrivateFieldGet(_focusedItem, this)) {
					_classPrivateFieldGet(_focusedItem, this).blur();
					_classPrivateFieldSet(_focusedItem, this, null);
				}
			}
		}]);
	}(main_core_events.EventEmitter);
	function _createPopup() {
		const domItems = [];
		for (let i = 0; i < this.menuItems.length; i++) {
			const item = this.menuItems[i];
			const itemLayout = item.getLayout();
			domItems.push(itemLayout.item);
		}
		const defaults = {
			closeByEsc: true,
			angle: false,
			autoHide: true,
			offsetTop: 1,
			offsetLeft: 0,
			animation: 'fading'
		};
		const options = Object.assign(defaults, this.params);

		// Override user params
		options.noAllPaddings = true;
		options.darkMode = false;
		options.autoHideHandler = _assertClassBrand(_Menu_brand, this, _handleAutoHide).bind(this);
		options.role = 'menu';
		if (main_core.Type.isNil(options.focusTrap) && Popup.shouldUseFocusTrapByDefault()) {
			options.focusTrap = {
				initialFocus: 'container'
			};
		}
		this.layout.itemsContainer = main_core.Tag.render`
			<div class="menu-popup-items" role="presentation">${domItems}</div>
		`;
		this.layout.menuContainer = main_core.Tag.render`
			<div class="menu-popup" role="presentation">${this.layout.itemsContainer}</div>
		`;
		this.itemsContainer = this.layout.itemsContainer;
		options.content = this.layout.menuContainer;

		// Make internal event handlers first in the queue.
		options.events = {
			onBeforeShow: _assertClassBrand(_Menu_brand, this, _handlePopupBeforeShow).bind(this),
			onShow: _assertClassBrand(_Menu_brand, this, _handlePopupShow).bind(this),
			onAfterShow: _assertClassBrand(_Menu_brand, this, _handlePopupAfterShow).bind(this),
			onClose: _assertClassBrand(_Menu_brand, this, _handlePopupClose).bind(this),
			onDestroy: _assertClassBrand(_Menu_brand, this, _handlePopupDestroy).bind(this)
		};
		const id = options.compatibleMode === false ? this.getId() : `menu-popup-${this.getId()}`;
		const popup = new Popup(id, this.bindElement, options);
		if (this.params && this.params.events) {
			popup.subscribeFromOptions(this.params.events);
		}
		return popup;
	}
	function _handlePopupBeforeShow() {
		this.emit('onBeforeShow');
		_classPrivateFieldSet(_ignoreMouseEnter, this, true);
	}
	function _handlePopupShow() {
		this.emit('onShow');
	}
	function _handlePopupAfterShow() {
		this.emit('onAfterShow');
		_classPrivateFieldSet(_ignoreMouseEnter, this, false);
	}
	function _handlePopupClose() {
		this.emit('onClose');
		for (let i = 0; i < this.menuItems.length; i++) {
			const item = this.menuItems[i];
			item.blur();
			item.closeSubMenu();
		}
	}
	function _handlePopupDestroy() {
		this.emit('onDestroy');
		for (let i = 0; i < this.menuItems.length; i++) {
			const item = this.menuItems[i];
			item.blur();
			item.destroySubMenu();
		}
	}
	function _handleAutoHide(event) {
		return !this.containsTarget(event.target);
	}
	function _handleItemFocus(event) {
		const {
			item
		} = event.getData();
		if (_classPrivateFieldGet(_focusedItem, this) === item) {
			return;
		}
		this.clearFocus();
		_classPrivateFieldSet(_focusedItem, this, item);
	}
	function _handleItemBlur() {
		this.clearFocus();
	}

	class MenuManager {
		/**
		 * @private
		 */
		static Data = {};

		/**
		 * @private
		 */
		static currentItem = null;
		constructor() {
			throw new Error('You cannot make an instance of MenuManager.');
		}
		static show(...args) {
			if (this.currentItem !== null) {
				this.currentItem.popupWindow.close();
			}
			this.currentItem = this.create.apply(this, args);
			this.currentItem.popupWindow.show();
		}
		static create(options) {
			let menuId = null;

			// Compatibility
			const bindElement = arguments[1];
			const menuItems = arguments[2];
			const params = arguments[3];
			if (main_core.Type.isPlainObject(options) && !bindElement && !menuItems && !params) {
				menuId = options.id;
				if (!main_core.Type.isStringFilled(menuId)) {
					throw new Error('BX.Main.Menu.create: "id" parameter is required.');
				}
			} else {
				menuId = options;
			}
			if (!this.Data[menuId]) {
				const menu = new Menu(options, bindElement, menuItems, params);
				menu.getPopupWindow().subscribe('onDestroy', () => {
					MenuManager.destroy(menuId);
				});
				this.Data[menuId] = menu;
			}
			return this.Data[menuId];
		}
		static getCurrentMenu() {
			return this.currentItem;
		}
		static getMenuById(id) {
			return this.Data[id] ? this.Data[id] : null;
		}

		/**
		 * compatibility
		 * @private
		 */
		static onPopupDestroy(popupMenuWindow) {
			this.destroy(popupMenuWindow.id);
		}
		static destroy(id) {
			const menu = this.getMenuById(id);
			if (menu) {
				if (this.currentItem === menu) {
					this.currentItem = null;
				}
				delete this.Data[id];
				menu.getPopupWindow().destroy();
			}
		}
	}

	class PopupManager {
		static _popups = [];
		static _currentPopup = null;
		constructor() {
			throw new Error('You cannot make an instance of PopupManager.');
		}
		static create(options) {
			let [popupId, bindElement, params] = arguments; // compatible arguments

			let id = popupId;
			let compatMode = true;
			if (main_core.Type.isPlainObject(popupId) && !bindElement && !params) {
				compatMode = false;
				id = popupId.id;
				if (!main_core.Type.isStringFilled(id)) {
					throw new Error('BX.Main.Popup.Manager: "id" parameter is required.');
				}
			}
			let popupWindow = this.getPopupById(id);
			if (popupWindow === null) {
				popupWindow = compatMode ? new Popup(popupId, bindElement, params) : new Popup(options);
				popupWindow.subscribe('onShow', this.handlePopupShow);
				popupWindow.subscribe('onClose', this.handlePopupClose);
			}
			return popupWindow;
		}

		/**
		 * @private
		 */
		static handleOnAfterInit(event) {
			event.getTarget().subscribeOnce('onDestroy', this.handlePopupDestroy);
			this._popups.forEach(popup => {
				if (popup.getId() === event.getTarget().getId()) {
					console.error(`Duplicate id (${popup.getId()}) for the BX.Main.Popup instance.`);
				}
			});
			this._popups.push(event.getTarget());
		}

		/**
		 * @private
		 */
		static handlePopupDestroy(event) {
			this._popups = this._popups.filter(popup => {
				return popup !== event.getTarget();
			});
		}

		/**
		 * @private
		 */
		static handlePopupShow(event) {
			if (this._currentPopup !== null) {
				this._currentPopup.close();
			}
			this._currentPopup = event.getTarget();
		}

		/**
		 * @private
		 */
		static handlePopupClose() {
			this._currentPopup = null;
		}
		static getCurrentPopup() {
			return this._currentPopup;
		}
		static isPopupExists(id) {
			return this.getPopupById(id) !== null;
		}
		static isAnyPopupShown() {
			for (let i = 0, length = this._popups.length; i < length; i++) {
				if (this._popups[i].isShown()) {
					return true;
				}
			}
			return false;
		}
		static getPopupById(id) {
			for (let i = 0; i < this._popups.length; i++) {
				if (this._popups[i].getId() === id) {
					return this._popups[i];
				}
			}
			return null;
		}
		static getMaxZIndex() {
			let zIndex = 0;
			this.getPopups().forEach(popup => {
				zIndex = Math.max(zIndex, popup.getZindex());
			});
			return zIndex;
		}
		static getPopups() {
			return this._popups;
		}
	}
	PopupManager.handlePopupDestroy = PopupManager.handlePopupDestroy.bind(PopupManager);
	PopupManager.handlePopupShow = PopupManager.handlePopupShow.bind(PopupManager);
	PopupManager.handlePopupClose = PopupManager.handlePopupClose.bind(PopupManager);
	PopupManager.handleOnAfterInit = PopupManager.handleOnAfterInit.bind(PopupManager);
	main_core_events.EventEmitter.subscribe('BX.Main.Popup:onAfterInit', PopupManager.handleOnAfterInit);

	/**
	 * @deprecated use BX.UI.Button
	 */
	class ButtonLink extends Button {
		constructor(params) {
			super(params);
			this.buttonNode = main_core.Dom.create('button', {
				props: {
					className: 'popup-window-button popup-window-button-link' + (this.className.length > 0 ? ` ${this.className}` : ''),
					id: this.id
				},
				attrs: {
					tabindex: '0',
					type: 'button'
				},
				text: this.text,
				events: this.contextEvents
			});
		}
	}

	/**
	 * @deprecated use BX.UI.Button
	 */
	class CustomButton extends Button {
		constructor(params) {
			super(params);
			this.buttonNode = main_core.Dom.create('span', {
				props: {
					className: this.className.length > 0 ? this.className : '',
					id: this.id
				},
				events: this.contextEvents,
				text: this.text
			});
		}
	}

	/**
	 * @deprecated
	 */
	class InputPopup {
		constructor(params) {
			this.id = params.id || 'bx-inp-popup-' + Math.round(Math.random() * 1000000);
			this.handler = params.handler || false;
			this.values = params.values || false;
			this.pInput = params.input;
			this.bValues = !!this.values;
			this.defaultValue = params.defaultValue || '';
			this.openTitle = params.openTitle || '';
			this.className = params.className || '';
			this.noMRclassName = params.noMRclassName || 'ec-no-rm';
			this.emptyClassName = params.noMRclassName || 'ec-label';
			const _this = this;
			this.curInd = false;
			if (this.bValues) {
				this.pInput.onfocus = this.pInput.onclick = function (e) {
					if (this.value == _this.defaultValue) {
						this.value = '';
						this.className = _this.className;
					}
					_this.ShowPopup();
					return e.preventDefault();
				};
				this.pInput.onblur = function () {
					if (_this.bShowed) {
						setTimeout(function () {
							_this.ClosePopup(true);
						}, 200);
					}
					_this.OnChange();
				};
			} else {
				this.pInput.className = this.noMRclassName;
				this.pInput.onblur = this.OnChange.bind(this);
			}
		}
		ShowPopup() {
			if (this.bShowed) {
				return;
			}
			const _this = this;
			if (!this.oPopup) {
				const pWnd = main_core.Dom.create('DIV', {
					props: {
						className: 'bxecpl-loc-popup ' + this.className
					}
				});
				for (let i = 0, l = this.values.length; i < l; i++) {
					const pRow = pWnd.appendChild(main_core.Dom.create('DIV', {
						props: {
							id: 'bxecmr_' + i
						},
						text: this.values[i].NAME,
						events: {
							mouseover: function () {
								main_core.Dom.addClass(this, 'bxecplloc-over');
							},
							mouseout: function () {
								main_core.Dom.removeClass(this, 'bxecplloc-over');
							},
							click: function () {
								const ind = this.id.substr('bxecmr_'.length);
								_this.pInput.value = _this.values[ind].NAME;
								_this.curInd = ind;
								_this.OnChange();
								_this.ClosePopup(true);
							}
						}
					}));
					if (this.values[i].DESCRIPTION) {
						pRow.title = this.values[i].DESCRIPTION;
					}
					if (this.values[i].CLASS_NAME) {
						main_core.Dom.addClass(pRow, this.values[i].CLASS_NAME);
					}
					if (this.values[i].URL) {
						pRow.appendChild(main_core.Dom.create('a', {
							props: {
								href: this.values[i].URL,
								className: 'bxecplloc-view',
								target: '_blank',
								title: this.openTitle
							}
						}));
					}
				}
				this.oPopup = new Popup(this.id, this.pInput, {
					autoHide: true,
					offsetTop: 1,
					offsetLeft: 0,
					lightShadow: true,
					closeByEsc: true,
					content: pWnd,
					events: {
						onClose: this.ClosePopup.bind(this)
					}
				});
			}
			this.oPopup.show();
			this.pInput.select();
			this.bShowed = true;
			main_core_events.EventEmitter.emit(this, 'onInputPopupShow', new main_core_events.BaseEvent({
				compatData: [this]
			}));
		}
		ClosePopup(bClosePopup) {
			this.bShowed = false;
			if (this.pInput.value === '') {
				this.OnChange();
			}
			main_core_events.EventEmitter.emit(this, 'onInputPopupClose', new main_core_events.BaseEvent({
				compatData: [this]
			}));
			if (bClosePopup === true) {
				this.oPopup.close();
			}
		}
		OnChange() {
			let val = this.pInput.value;
			if (this.bValues) {
				if (this.pInput.value == '' || this.pInput.value == this.defaultValue) {
					this.pInput.value = this.defaultValue;
					this.pInput.className = this.emptyClassName;
					val = '';
				} else {
					this.pInput.className = '';
				}
			}
			if (isNaN(parseInt(this.curInd)) || this.curInd !== false && val != this.values[this.curInd].NAME) {
				this.curInd = false;
			} else {
				this.curInd = parseInt(this.curInd);
			}
			main_core_events.EventEmitter.emit(this, 'onInputPopupChanged', new main_core_events.BaseEvent({
				compatData: [this, this.curInd, val]
			}));
			if (this.handler && typeof this.handler == 'function') {
				this.handler({
					ind: this.curInd,
					value: val
				});
			}
		}
		Set(ind, val, bOnChange) {
			this.curInd = ind;
			if (this.curInd !== false) {
				this.pInput.value = this.values[this.curInd].NAME;
			} else {
				this.pInput.value = val;
			}
			if (bOnChange !== false) {
				this.OnChange();
			}
		}
		Get(ind) {
			let id = false;
			if (typeof ind == 'undefined') {
				ind = this.curInd;
			}
			if (ind !== false && this.values[ind]) {
				id = this.values[ind].ID;
			}
			return id;
		}
		GetIndex(id) {
			for (let i = 0, l = this.values.length; i < l; i++) {
				if (this.values[i].ID == id) {
					return i;
				}
			}
			return false;
		}
		Deactivate(bDeactivate) {
			if (this.pInput.value == '' || this.pInput.value == this.defaultValue) {
				if (bDeactivate) {
					this.pInput.value = '';
					this.pInput.className = this.noMRclassName;
				} else if (this.oEC.bUseMR) {
					this.pInput.value = this.defaultValue;
					this.pInput.className = this.emptyClassName;
				}
			}
			this.pInput.disabled = bDeactivate;
		}
	}

	/**
	 * @deprecated use Menu.Item class instead: import { MenuItem } from 'main.popup'
	 */
	class PopupMenuItem extends MenuItem {
		// No additional functionality, just for compatibility
	}

	/**
	 * @deprecated use Menu class instead: import { Menu } from 'main.popup'
	 */
	class PopupMenuWindow extends Menu {
		// No additional functionality, just for compatibility
	}

	/**
	 * @deprecated use Popup class instead: import { Popup } from 'main.popup'
	 */
	class PopupWindow extends Popup {
		// No additional functionality, just for compatibility
	}

	/**
	 * @deprecated use BX.UI.Button
	 */
	class PopupWindowButton extends Button {
		// No additional functionality, just for compatibility
	}

	/**
	 * @deprecated use BX.UI.Button
	 */
	class PopupWindowButtonLink extends ButtonLink {
		// No additional functionality, just for compatibility
	}

	/**
	 * @deprecated use BX.UI.Button
	 */
	class PopupWindowCustomButton extends CustomButton {
		// No additional functionality, just for compatibility
	}

	/*

	//ES6
	import { Popup, PopupManager, CloseIconSize } from 'main.popup';
	const popup = new Popup();
	PopupManager.create();

	//ES5
	var popup = new BX.Main.Popup();
	BX.Main.PopupManager.create();
	BX.Main.Popup.CloseIconSize;

	//ES6
	import { Menu, MenuItem, MenuManager } from 'main.popup';
	const menu = new Menu();
	const item = new MenuItem();
	MenuManager.create();

	//ES5
	var menu = new BX.Main.Menu();
	var item = new BX.Main.MenuItem();
	BX.Main.MenuManager.create();

	 */

	const BX = main_core.Reflection.namespace('BX');

	/** @deprecated use BX.Main.Popup or import { Popup } from 'main.popup' */
	BX.PopupWindow = Popup;

	/** @deprecated use BX.Main.PopupManager or import { PopupManager } from 'main.popup' */
	BX.PopupWindowManager = PopupManager;

	/** @deprecated use BX.Main.Menu or import { Menu } from 'main.popup' */
	BX.PopupMenuWindow = Menu;

	/** @deprecated use BX.Main.MenuManager or import { MenuManager } from 'main.popup' */
	BX.PopupMenu = MenuManager;

	/** @deprecated use BX.Main.MenuItem or import { MenuItem } from 'main.popup' */
	BX.PopupMenuItem = MenuItem;

	/** @deprecated use BX.UI.Button */
	BX.PopupWindowButton = Button;

	/** @deprecated use BX.UI.Button */
	BX.PopupWindowButtonLink = ButtonLink;

	/** @deprecated use BX.UI.Button */
	BX.PopupWindowCustomButton = CustomButton;

	/** @deprecated use another API */
	window.BXInputPopup = InputPopup;

	exports.CloseIconSize = CloseIconSize;
	exports.Menu = Menu;
	exports.MenuItem = MenuItem;
	exports.MenuManager = MenuManager;
	exports.Popup = Popup;
	exports.PopupManager = PopupManager;
	exports.PopupMenu = MenuManager;
	exports.PopupMenuItem = PopupMenuItem;
	exports.PopupMenuWindow = PopupMenuWindow;
	exports.PopupWindow = PopupWindow;
	exports.PopupWindowButton = PopupWindowButton;
	exports.PopupWindowButtonLink = PopupWindowButtonLink;
	exports.PopupWindowCustomButton = PopupWindowCustomButton;
	exports.PopupWindowManager = PopupManager;

})(this.BX.Main = this.BX.Main || {}, BX, BX, BX, BX.Event, BX.UI.Accessibility, BX);
//# sourceMappingURL=main.popup.bundle.js.map
