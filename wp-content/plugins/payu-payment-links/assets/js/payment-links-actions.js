jQuery(document).on('click', '.payu-actions-toggle', function (e) {
	e.preventDefault();
	jQuery(this).closest('.payu-actions').toggleClass('open');
});

jQuery(document).on('click', '.payu-copy-btn', function () {
	const btn = jQuery(this);
	const url = btn.data('url');

	navigator.clipboard.writeText(url).then(() => {
		btn.addClass('copied');
		btn.find('.dashicons').removeClass('dashicons-admin-links').addClass('dashicons-yes');

		setTimeout(() => {
			btn.removeClass('copied');
			btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-admin-links');
		}, 1200);
	});
});