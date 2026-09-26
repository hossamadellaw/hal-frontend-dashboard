/**
 * runtime/assets/js/admin-settings.js — واجهة Backend (الدفعة 3).
 *
 * العقد (معمارية §14):
 *   - اختيار الشعار عبر WordPress Media modal فقط (wp.media)، وضبط
 *     attachment id في الحقل المخفي — لا مسارات ملفات ولا URLs حرة.
 *   - تبعيات المميزات: عناصر [data-requires-feature] تُعطَّل عندما
 *     يكون المميزة الموافقة غير مفعلة (تنبيه واجهة فقط؛ التحقق
 *     الخادمي هو الحاكم).
 *   - إخفاء قيم الأسرار: حقول password بلا قيمة أولية من الخادم —
 *     لا يُقرأ أي secret من أي استجابة ولا يُكتب في DOM نصيًا.
 *   - منع الإرسال المكرر: أول submit يعطّل الأزرار (aria-busy) حتى
 *     مغادرة الصفحة.
 *   - حالات الخطأ: غياب wp.media يظهر رسالة منقحة (بلا تفاصيل).
 */
(function () {
	'use strict';

	function qsAll(root, selector) {
		return Array.prototype.slice.call(root.querySelectorAll(selector));
	}

	function showError(container, message) {
		var note = container.querySelector('[data-hal-js-error]');
		if (!note) {
			note = document.createElement('p');
			note.className = 'hal-fd-js-error';
			note.setAttribute('data-hal-js-error', '');
			container.appendChild(note);
		}
		// نص منقح من التوطين فقط — لا يُدرج فيه أي محتوى خارجي.
		note.textContent = message;
	}

	function initMediaPicker(root, strings) {
		var openButton = root.querySelector('[data-hal-media-open]');
		var input = root.querySelector('[data-hal-attachment-input]');
		var preview = root.querySelector('[data-hal-attachment-preview]');
		var card = root.querySelector('.hal-fd-card') || root;
		if (!openButton || !input) {
			return;
		}
		var frame = null;
		openButton.addEventListener('click', function () {
			if (!window.wp || !window.wp.media) {
				showError(card, strings.mediaUnavailable || 'Media library unavailable.');
				return;
			}
			if (!frame) {
				var options = {
					title: strings.mediaTitle || '',
					button: { text: strings.mediaButton || '' },
					library: { type: 'image' },
					multiple: false
				};
				// §7.6: رفع Media جديد يحتاج upload_files — القيود الخادمية
				// لووردبريس نفسه؛ بلا هذه الصلاحية تُفتح نافذة اختيار فقط
				// (uploader مخفي)، والاختيار من الموجود يبقى متاحًا.
				if (strings.canUploadFiles === false) {
					options.uploader = false;
				}
				frame = window.wp.media(options);
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first();
					if (!attachment) {
						return;
					}
					var id = parseInt(attachment.get('id'), 10);
					if (!id || id <= 0) {
						showError(card, strings.mediaUnavailable || 'Invalid attachment.');
						return;
					}
					input.value = String(id);
					if (preview) {
						preview.setAttribute('data-hal-attachment-id', String(id));
						preview.textContent = (strings.selectedLabel || 'Selected attachment id: ') + id;
					}
				});
			}
			frame.open();
		});
	}

	function initFeatureDependencies(root) {
		var toggles = qsAll(root, '[data-hal-feature]');
		if (!toggles.length) {
			return;
		}
		function apply() {
			var enabled = {};
			toggles.forEach(function (toggle) {
				enabled[toggle.getAttribute('data-hal-feature')] = toggle.checked;
			});
			qsAll(root, '[data-requires-feature]').forEach(function (element) {
				var required = element.getAttribute('data-requires-feature');
				if (Object.prototype.hasOwnProperty.call(enabled, required)) {
					element.classList.toggle('hal-fd-disabled', !enabled[required]);
					element.disabled = !enabled[required];
				}
			});
		}
		toggles.forEach(function (toggle) {
			toggle.addEventListener('change', apply);
		});
		apply();
	}

	function initSubmitGuard(root) {
		qsAll(root, 'form[data-hal-form]').forEach(function (form) {
			var submitted = false;
			form.addEventListener('submit', function (event) {
				if (submitted) {
					event.preventDefault();
					return;
				}
				submitted = true;
				form.setAttribute('aria-busy', 'true');
				qsAll(form, 'button, input[type="submit"]').forEach(function (button) {
					button.disabled = true;
				});
			});
		});
	}

	function init(root, strings) {
		if (!root || root.getAttribute('data-hal-initialized') === '1') {
			return;
		}
		root.setAttribute('data-hal-initialized', '1');
		strings = strings || {};
		initMediaPicker(root, strings);
		initFeatureDependencies(root);
		initSubmitGuard(root);
	}

	// نافذة واجهة للاختبار المعزول والتهيئة عند التحميل.
	window.HALFrontendDashboardAdminSettings = {
		init: init
	};

	if (typeof document !== 'undefined') {
		var boot = function () {
			var root = document.querySelector('[data-hal-admin]');
			var strings = window.halFrontendDashboardAdmin || {};
			init(root, strings);
		};
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', boot);
		} else {
			boot();
		}
	}
}());
