/**
 * The «print label» link from DHL's mail (D-976): the plugin fetches that PDF, the Etiketten module's taxmod-label62 crops it to
 * 62 mm and prints it (D-1507). Nothing is stored.
 */
(function () {
	'use strict';

	document.addEventListener('click', async function (event) {
		var button = event.target.closest('[data-taxebay-link]');

		if (!button) {
			return;
		}

		event.preventDefault();
		var input = document.getElementById(button.getAttribute('data-taxebay-input'));
		var link = input ? input.value.trim() : '';

		if (!link) {
			window.alert(taxebayLabel.noLink);
			return;
		}

		button.disabled = true;

		try {
			var response = await fetch(taxebayLabel.fetch + '&url=' + encodeURIComponent(link), { credentials: 'same-origin' });

			if (!response.ok) {
				throw new Error((await response.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200));
			}

			if (await taxmodLabel62.run(new Blob([await response.arrayBuffer()], { type: 'application/pdf' }), button.getAttribute('data-taxebay-link'))) {
				// tax_mail deletes a printed label after 14 days; a failed note only means it waits the full 30.
				fetch(taxebayLabel.printed, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ url: link }) }).catch(function () {});
			}
		} catch (failure) {
			window.alert(taxebayLabel.failed + ' ' + failure.message);
		} finally {
			button.disabled = false;
		}
	});
}());
