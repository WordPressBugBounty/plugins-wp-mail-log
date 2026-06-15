/**
 * WPVibes Review Reminder
 * Vanilla JS, no jQuery dependency.
 */
(function () {
	'use strict';

	function init() {
		var reminders = document.querySelectorAll('.wpvibes-review-reminder');
		Array.prototype.forEach.call(reminders, function (reminder) {
			var slug = reminder.getAttribute('data-slug');
			var nonce = reminder.getAttribute('data-nonce');
			var ajaxUrl = reminder.getAttribute('data-ajax-url');
			var ajaxAction = reminder.getAttribute('data-ajax-action') || 'wpvibes_review_reminder_action';

			reminder.addEventListener('click', function (event) {
				var target = event.target.closest('[data-action]');
				if (!target) {
					return;
				}
				var action = target.getAttribute('data-action');

				// "Rate now" follows the link AND records the rated state.
				// Other actions are pure state transitions.
				if (action !== 'rate') {
					event.preventDefault();
				}

				sendAction(ajaxUrl, slug, nonce, action, ajaxAction, function (success) {
					if (action === 'rate') {
						return; // already navigating
					}
					if (success) {
						hideReminder(reminder);
					}
				});
			});
		});
	}

	function sendAction(url, slug, nonce, action, ajaxAction, done) {
		var body = new URLSearchParams();
		body.append('action', ajaxAction);
		body.append('slug', slug);
		body.append('reminder_action', action);
		body.append('_wpnonce', nonce);

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (res) { return res.json().catch(function () { return { success: false }; }); })
			.then(function (data) { done(!!(data && data.success)); })
			.catch(function () { done(false); });
	}

	function hideReminder(reminder) {
		reminder.classList.add('is-dismissing');
		setTimeout(function () {
			if (reminder.parentNode) {
				reminder.parentNode.removeChild(reminder);
			}
		}, 200);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
