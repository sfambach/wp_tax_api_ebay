/**
 * Fits an eBay shipping label (PDF, image or screenshot) onto the 62 mm tape of the Brother QL-810W: every page is rendered, cropped to
 * its printed area, turned upright and printed at tape width with order number and seller in its empty corner. The PDF never
 * leaves the browser.
 */
(function () {
	'use strict';

	var TAPE_MM = 62;
	// The QL-810W cannot print the outer 1.5 mm of the tape; 3 mm keep clear of it.
	var PRINT_MM = 58;
	// The Brother driver's paper for endless 62 mm tape is 62 × 100 mm; a longer page is shrunk by the browser to fit
	// it (the first prints came out at half width). So the label fits into one such page.
	var PAGE_MM = 100;
	// The QL-810W leaves about 3 mm unprinted at the start and end of each label.
	var FREE_MM = PAGE_MM - 9;
	var DPI = 300;

	pdfjsLib.GlobalWorkerOptions.workerSrc = taxebayLabel.worker;

	// Ink only: light grey (frames, cut marks, anti-aliasing) and single specks do not widen the label. Only the
	// first block of ink counts — eBay's PDF carries the postage label on top and notes further down the page.
	// gap: the blank height in pixels that ends the block.
	function crop(canvas, gap) {
		var data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
		var ink = new Uint8Array(canvas.width * canvas.height);
		var rows = new Uint32Array(canvas.height), across = new Uint32Array(canvas.width);
		var MIN = 3;

		for (var p = 0; p < ink.length; p++) {
			if (data[p * 4] + data[p * 4 + 1] + data[p * 4 + 2] < 384) {
				ink[p] = 1;
				across[p % canvas.width]++;
			}
		}

		// Lines through nearly the whole picture are a window or page frame (a screenshot), not the label.
		for (var y = 0; y < canvas.height; y++) {
			for (var x = 0; x < canvas.width; x++) {
				if (ink[y * canvas.width + x] && across[x] < canvas.height * 0.8) {
					rows[y]++;
				}
			}

			if (rows[y] > canvas.width * 0.8) {
				rows[y] = 0;
			}
		}

		var top = rows.findIndex(function (n) { return n >= MIN; });

		if (top < 0) {
			return null;
		}

		var bottom = top;

		for (var row = top, blank = 0; row < canvas.height && blank < gap; row++) {
			if (rows[row] >= MIN) {
				bottom = row;
				blank = 0;
			} else {
				blank++;
			}
		}

		var columns = new Uint32Array(canvas.width);

		for (var y2 = top; y2 <= bottom; y2++) {
			for (var x2 = 0; x2 < canvas.width; x2++) {
				if (ink[y2 * canvas.width + x2] && across[x2] < canvas.height * 0.8) {
					columns[x2]++;
				}
			}
		}

		var left = columns.findIndex(function (n) { return n >= MIN; });
		var right = canvas.width - 1;
		while (columns[right] < MIN) { right--; }

		var pad = Math.round(gap / 12);
		left = Math.max(0, left - pad);
		top = Math.max(0, top - pad);
		right = Math.min(canvas.width - 1, right + pad);
		bottom = Math.min(canvas.height - 1, bottom + pad);

		var width = right - left + 1, height = bottom - top + 1;
		// Long side along the tape: the short side then fills its width and the label prints large.
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
		context.setTransform(1, 0, 0, 1, 0, 0);
		// Pure black and white: grey edges are dithered by the thermal printer and blur the matrix code.
		var image = context.getImageData(0, 0, out.width, out.height);

		for (var k = 0; k < image.data.length; k += 4) {
			var value = image.data[k] + image.data[k + 1] + image.data[k + 2] < 540 ? 0 : 255;
			image.data[k] = image.data[k + 1] = image.data[k + 2] = value;
			image.data[k + 3] = 255;
		}

		context.putImageData(image, 0, 0);
		out.upright = upright;
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
		// A screenshot is usually cut close already; only a wide blank (a fifth of it) ends the label.
		var label = crop(canvas, Math.round(canvas.height * 0.2));
		return label ? [label] : [];
	}

	async function labels(file) {
		if (file.type.indexOf('image/') === 0) {
			return picture(file);
		}

		var pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer(), cMapUrl: taxebayLabel.cmaps, cMapPacked: true, standardFontDataUrl: taxebayLabel.fonts }).promise;
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
			// Print intent with form fields drawn in: eBay's postage label keeps stamp and address in annotations.
			await page.render({ canvasContext: context, viewport: viewport, intent: 'print', annotationMode: pdfjsLib.AnnotationMode.ENABLE_STORAGE }).promise;
			var label = crop(canvas, Math.round(DPI / 25.4 * 12));

			if (label) {
				pages.push(label);
			}
		}

		return pages;
	}

	// Order and seller go into the empty bottom right corner of the label itself, so the label keeps the full page.
	function stamp(label, caption) {
		var context = label.getContext('2d');
		var size = Math.round(Math.min(label.width, label.height) * 0.035);
		var margin = Math.round(size * 0.6);
		context.save();
		context.fillStyle = '#000';
		context.font = size + 'px sans-serif';
		context.textAlign = 'right';

		if (label.upright) {
			context.translate(margin, label.height - margin);
			context.rotate(Math.PI / 2);
		} else {
			context.translate(label.width - margin, label.height - margin);
		}

		context.fillText(caption, 0, 0);
		context.restore();
	}

	function print(pages, caption) {
		var frame = document.createElement('iframe');
		frame.style.cssText = 'position:fixed;width:0;height:0;border:0';
		document.body.appendChild(frame);
		var doc = frame.contentDocument;
		var body = '';
		pages.forEach(function (label) {
			var width = Math.min(PRINT_MM, FREE_MM * label.width / label.height);
			stamp(label, caption);
			body += '<section><img style="width:' + width.toFixed(1) + 'mm" src="' + label.toDataURL('image/png') + '" alt=""></section>';
		});

		doc.open();
		doc.write('<!doctype html><html><head><meta charset="utf-8"><style>'
			+ '@page{size:' + TAPE_MM + 'mm ' + PAGE_MM + 'mm;margin:0}'
			+ 'body{margin:0;font:7pt/1.2 sans-serif}section{padding:4.5mm 2mm}section+section{break-before:page}'
			+ 'img{display:block;margin:0 auto;image-rendering:pixelated}</style></head><body>' + body + '</body></html>');
		doc.close();

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

	// The «print label» link from DHL's mail: the plugin fetches the PDF, the browser crops and prints it.
	document.addEventListener('click', async function (event) {
		var button = event.target.closest('[data-taxebay-link]');

		if (!button) {
			return;
		}

		event.preventDefault();
		var input = button.parentNode.querySelector('[data-taxebay-link-input]');
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

			await run(new Blob([await response.arrayBuffer()], { type: 'application/pdf' }), button.getAttribute('data-taxebay-link'));
		} catch (failure) {
			window.alert(taxebayLabel.failed + ' ' + failure.message);
		} finally {
			button.disabled = false;
		}
	});
}());
