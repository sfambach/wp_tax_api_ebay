/**
 * Fits an eBay shipping label (PDF, image or screenshot) onto the 62 mm tape of the Brother QL-810W: every page is rendered, cropped to
 * its printed area, turned upright and printed at tape width with order number and seller underneath. The PDF never
 * leaves the browser.
 */
(function () {
	'use strict';

	var TAPE_MM = 62;
	var PRINT_MM = 58;
	var DPI = 300;

	pdfjsLib.GlobalWorkerOptions.workerSrc = taxebayLabel.worker;

	function crop(canvas) {
		var data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
		var left = canvas.width, top = canvas.height, right = -1, bottom = -1;

		for (var y = 0; y < canvas.height; y++) {
			for (var x = 0; x < canvas.width; x++) {
				var i = (y * canvas.width + x) * 4;

				if (data[i] + data[i + 1] + data[i + 2] < 600) {
					if (x < left) { left = x; }
					if (x > right) { right = x; }
					if (y < top) { top = y; }
					if (y > bottom) { bottom = y; }
				}
			}
		}

		if (right < 0) {
			return null;
		}

		var width = right - left + 1, height = bottom - top + 1;
		var upright = width > height;
		var out = document.createElement('canvas');
		out.width = upright ? height : width;
		out.height = upright ? width : height;
		var context = out.getContext('2d');

		if (upright) {
			context.translate(out.width, 0);
			context.rotate(Math.PI / 2);
		}

		context.drawImage(canvas, left, top, width, height, 0, 0, width, height);
		return out;
	}

	async function picture(blob) {
		var bitmap = await createImageBitmap(blob);
		var canvas = document.createElement('canvas');
		canvas.width = bitmap.width;
		canvas.height = bitmap.height;
		var context = canvas.getContext('2d');
		context.fillStyle = '#fff';
		context.fillRect(0, 0, canvas.width, canvas.height);
		context.drawImage(bitmap, 0, 0);
		var label = crop(canvas);
		return label ? [label] : [];
	}

	async function labels(file) {
		if (file.type.indexOf('image/') === 0) {
			return picture(file);
		}

		var pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
		var pages = [];

		for (var number = 1; number <= pdf.numPages; number++) {
			var page = await pdf.getPage(number);
			var viewport = page.getViewport({ scale: DPI / 72 });
			var canvas = document.createElement('canvas');
			canvas.width = Math.ceil(viewport.width);
			canvas.height = Math.ceil(viewport.height);
			var context = canvas.getContext('2d');
			context.fillStyle = '#fff';
			context.fillRect(0, 0, canvas.width, canvas.height);
			await page.render({ canvasContext: context, viewport: viewport }).promise;
			var label = crop(canvas);

			if (label) {
				pages.push(label);
			}
		}

		return pages;
	}

	function print(pages, caption) {
		var frame = document.createElement('iframe');
		frame.style.cssText = 'position:fixed;width:0;height:0;border:0';
		document.body.appendChild(frame);
		var doc = frame.contentDocument;
		var body = '';
		var longest = 0;

		pages.forEach(function (label) {
			longest = Math.max(longest, PRINT_MM * label.height / label.width);
			body += '<section><img src="' + label.toDataURL('image/png') + '" alt=""><p></p></section>';
		});

		doc.open();
		doc.write('<!doctype html><html><head><meta charset="utf-8"><style>'
			+ '@page{size:' + TAPE_MM + 'mm ' + Math.ceil(longest + 10) + 'mm;margin:0}'
			+ 'body{margin:0;font:7pt/1.2 sans-serif}section{padding:2mm}section+section{break-before:page}'
			+ 'img{display:block;width:' + PRINT_MM + 'mm}p{margin:1mm 0 0}</style></head><body>' + body + '</body></html>');
		doc.close();
		doc.querySelectorAll('p').forEach(function (line) { line.textContent = caption; });

		var images = Array.prototype.slice.call(doc.images);
		Promise.all(images.map(function (image) {
			return image.complete ? null : new Promise(function (done) { image.onload = done; });
		})).then(function () {
			frame.contentWindow.focus();
			frame.contentWindow.print();
			setTimeout(function () { frame.remove(); }, 60000);
		});
	}

	function run(source, caption) {
		return labels(source).then(function (pages) {
			if (!pages.length) {
				window.alert(taxebayLabel.empty);
				return;
			}

			print(pages, caption);
		}).catch(function (failure) {
			window.alert(taxebayLabel.failed + ' ' + failure.message);
		});
	}

	document.addEventListener('change', function (event) {
		var input = event.target;

		if (!input.matches('input[data-taxebay-label]') || !input.files.length) {
			return;
		}

		run(input.files[0], input.getAttribute('data-taxebay-label')).finally(function () {
			input.value = '';
		});
	});

	// A screenshot of the label from the clipboard; reading it needs the browser's permission.
	document.addEventListener('click', async function (event) {
		var button = event.target.closest('[data-taxebay-paste]');

		if (!button) {
			return;
		}

		event.preventDefault();

		try {
			var items = await navigator.clipboard.read();

			for (var i = 0; i < items.length; i++) {
				var type = items[i].types.find(function (kind) { return kind.indexOf('image/') === 0; });

				if (type) {
					await run(await items[i].getType(type), button.getAttribute('data-taxebay-paste'));
					return;
				}
			}

			window.alert(taxebayLabel.noImage);
		} catch (failure) {
			window.alert(taxebayLabel.failed + ' ' + failure.message);
		}
	});
}());
