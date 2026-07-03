/**
 * Veedy Store — Omnitype-style floating help bar.
 * Vanilla JS, no dependencies. Enqueued from functions.php.
 *
 * Injects a fixed bottom-right round button that opens a small panel
 * with contact links (email, Discord, Instagram, WhatsApp).
 */
(function () {
	'use strict';

	if (document.querySelector('.vd-help-bar')) {
		return;
	}

	// Contact endpoints — kept here so the orchestrator can edit in one place.
	var contacts = [
		{
			label: 'Email',
			href: 'mailto:storeveedy@gmail.com',
			icon: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm0 2.5V18h18V7.5l-9 5.2L3 7.5Zm1.4-1.5L12 11l7.6-5H4.4Z"/></svg>',
		},
		{
			label: 'Discord',
			href: 'https://discord.gg/veedy',
			icon: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M19.3 5.3A17 17 0 0 0 15 4l-.2.4a14 14 0 0 1 3.7 1.2 16 16 0 0 0-13-.1A14 14 0 0 1 9.3 4L9 4a17 17 0 0 0-4.3 1.3C2 9.1 1.3 12.8 1.6 16.4A17 17 0 0 0 6.8 19l.5-.7a11 11 0 0 1-1.7-.8l.4-.3a12 12 0 0 0 10 0l.4.3a11 11 0 0 1-1.7.8l.5.7a17 17 0 0 0 5.2-2.6c.4-4.2-.6-7.9-2.8-11.1ZM8.5 14.3c-1 0-1.8-.9-1.8-2s.8-2 1.8-2 1.8.9 1.8 2-.8 2-1.8 2Zm7 0c-1 0-1.8-.9-1.8-2s.8-2 1.8-2 1.8.9 1.8 2-.8 2-1.8 2Z"/></svg>',
		},
		{
			label: 'Instagram',
			href: 'https://instagram.com/realveedy',
			icon: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.3 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.1.4.3 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.3 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.1-1 .3-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.3-2.2-.4a3.8 3.8 0 0 1-1.4-.9 3.8 3.8 0 0 1-.9-1.4c-.1-.4-.3-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.3-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.1 1-.3 2.2-.4C8.4 2.2 8.8 2.2 12 2.2Zm0 1.8c-3.1 0-3.5 0-4.7.1-1.1.1-1.7.2-2.1.4-.5.2-.9.4-1.3.8-.4.4-.6.8-.8 1.3-.2.4-.3 1-.4 2.1C2.6 9.5 2.6 9.9 2.6 13s0 3.5.1 4.7c.1 1.1.2 1.7.4 2.1.2.5.4.9.8 1.3.4.4.8.6 1.3.8.4.2 1 .3 2.1.4 1.2.1 1.6.1 4.7.1s3.5 0 4.7-.1c1.1-.1 1.7-.2 2.1-.4.5-.2.9-.4 1.3-.8.4-.4.6-.8.8-1.3.2-.4.3-1 .4-2.1.1-1.2.1-1.6.1-4.7s0-3.5-.1-4.7c-.1-1.1-.2-1.7-.4-2.1a3.5 3.5 0 0 0-.8-1.3 3.5 3.5 0 0 0-1.3-.8c-.4-.2-1-.3-2.1-.4-1.2-.1-1.6-.1-4.7-.1Zm0 3.1a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8Zm0 1.8a3.1 3.1 0 1 0 0 6.2 3.1 3.1 0 0 0 0-6.2Zm5.1-3.3a1.1 1.1 0 1 1 0 2.3 1.1 1.1 0 0 1 0-2.3Z"/></svg>',
		},
		{
			label: 'WhatsApp',
			href: 'https://wa.me/628000000000',
			icon: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.8 4.9-1.3A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 0 1 6.9 12.6l-.2.3.7 2.7-2.7-.7-.3.2A8.2 8.2 0 1 1 12 3.8Zm-3 4c-.2 0-.5 0-.7.3-.3.3-.9.8-.9 2s.9 2.3 1 2.5c.1.2 1.7 2.7 4.2 3.7 2.1.8 2.5.7 3 .6.5-.1 1.4-.6 1.6-1.1.2-.5.2-1 .1-1.1 0-.1-.2-.2-.5-.3l-1.4-.7c-.2-.1-.4-.1-.5.1l-.5.6c-.1.2-.3.2-.5.1-.7-.3-1.4-.6-2-1.5-.2-.3 0-.5.1-.6l.4-.5c.1-.1.1-.3 0-.4l-.6-1.5c-.2-.4-.4-.4-.5-.4h-.4Z"/></svg>',
		},
	];

	var bar = document.createElement('div');
	bar.className = 'vd-help-bar';
	bar.setAttribute('role', 'region');
	bar.setAttribute('aria-label', 'Bantuan');

	var btn = document.createElement('button');
	btn.type = 'button';
	btn.className = 'vd-help-bar__btn';
	btn.setAttribute('aria-label', 'Buka panel bantuan');
	btn.setAttribute('aria-expanded', 'false');
	btn.innerHTML =
		'<svg class="vd-help-bar__icon vd-help-bar__icon--closed" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 4a1 1 0 0 1 1 1v5a1 1 0 1 1-2 0V7a1 1 0 0 1 1-1Zm0 11a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6Z"/></svg>' +
		'<svg class="vd-help-bar__icon vd-help-bar__icon--open" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

	var panel = document.createElement('div');
	panel.className = 'vd-help-panel';
	panel.setAttribute('role', 'dialog');
	panel.setAttribute('aria-label', 'Hubungi Veedy Store');
	panel.hidden = true;

	var head = document.createElement('div');
	head.className = 'vd-help-panel__head';
	head.innerHTML =
		'<p class="vd-help-panel__title">Butuh bantuan?</p>' +
		'<p class="vd-help-panel__sub">Hubungi kami — kami balas di jam kerja.</p>';

	var list = document.createElement('ul');
	list.className = 'vd-help-panel__list';
	var i, c, li, a;
	for (i = 0; i < contacts.length; i++) {
		c = contacts[i];
		li = document.createElement('li');
		li.className = 'vd-help-panel__item';
		a = document.createElement('a');
		a.className = 'vd-help-panel__link';
		a.href = c.href;
		if (c.href.indexOf('http') === 0) {
			a.target = '_blank';
			a.rel = 'noopener';
		}
		a.innerHTML = '<span class="vd-help-panel__icon">' + c.icon + '</span><span class="vd-help-panel__label">' + c.label + '</span>';
		li.appendChild(a);
		list.appendChild(li);
	}

	panel.appendChild(head);
	panel.appendChild(list);
	bar.appendChild(panel);
	bar.appendChild(btn);
	document.body.appendChild(bar);

	function toggle(open) {
		var isOpen = typeof open === 'boolean' ? open : panel.hidden;
		panel.hidden = !isOpen;
		bar.classList.toggle('is-open', isOpen);
		btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
	}

	btn.addEventListener('click', function (e) {
		e.stopPropagation();
		toggle(panel.hidden);
	});

	// Close on outside click.
	document.addEventListener('click', function (e) {
		if (!panel.hidden && !bar.contains(e.target)) {
			toggle(false);
		}
	});

	// Close on Escape.
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !panel.hidden) {
			toggle(false);
			btn.focus();
		}
	});
})();