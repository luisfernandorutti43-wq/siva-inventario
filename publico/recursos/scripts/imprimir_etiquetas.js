const labelPage = document.querySelector('[data-print-labels="true"]');

if (labelPage) {
	const connectButton = labelPage.querySelector('[data-connect-printer]');
	const disconnectButton = labelPage.querySelector('[data-disconnect-printer]');
	const printButton = labelPage.querySelector('[data-print-direct]');
	const cancelButton = labelPage.querySelector('[data-cancel-print]');
	const status = labelPage.querySelector('[data-print-status]');
	let serialPort = null;

	const setStatus = (message) => {
		if (status) {
			status.textContent = message;
		}
	};

	cancelButton?.addEventListener('click', async (event) => {
		if (!serialPort) {
			return;
		}

		event.preventDefault();
		cancelButton.setAttribute('aria-disabled', 'true');
		try {
			await serialPort.close();
			serialPort = null;
		} catch (error) {
			cancelButton.removeAttribute('aria-disabled');
			const detail = error instanceof Error ? error.message : String(error);
			setStatus(`No se pudo desconectar la máquina; no se salió de la página: ${detail}`);
			return;
		}

		window.location.assign(cancelButton.href);
	});

	const labelText = (label, selector) => label.querySelector(selector)?.textContent.trim() ?? '';

	const drawWrappedText = (context, text, baseline, font, maxWidth, lineHeight, maxLines = 2, centerX = 192) => {
		context.font = font;
		context.textAlign = 'center';
		context.fillStyle = '#000';

		const words = text.split(/\s+/).filter(Boolean);
		const lines = [];
		let line = '';

		for (const word of words) {
			const candidate = line === '' ? word : `${line} ${word}`;
			if (line !== '' && context.measureText(candidate).width > maxWidth) {
				lines.push(line);
				line = word;
			} else {
				line = candidate;
			}
		}
		if (line !== '') {
			lines.push(line);
		}

		const visibleLines = lines.slice(0, maxLines);
		if (lines.length > maxLines) {
			visibleLines[maxLines - 1] = `${visibleLines[maxLines - 1]}…`;
		}
		visibleLines.forEach((value, index) => {
			context.fillText(value, centerX, baseline + index * lineHeight, maxWidth);
		});
	};

	const drawSvg = (context, svg, x, y, width, height) => {
		if (!svg || !svg.viewBox.baseVal.width || !svg.viewBox.baseVal.height) {
			throw new Error('No se pudo leer el código de la etiqueta.');
		}

		context.save();
		context.translate(x, y);
		context.scale(width / svg.viewBox.baseVal.width, height / svg.viewBox.baseVal.height);

		const paths = [...svg.querySelectorAll('path')];
		if (paths.length > 0) {
			for (const path of paths) {
				const fill = path.getAttribute('fill') ?? '#000';
				if (fill === 'none' || fill === 'transparent') {
					continue;
				}
				context.fillStyle = fill;
				context.fill(new Path2D(path.getAttribute('d') ?? ''));
			}
		} else {
			context.fillStyle = '#000';
			for (const rectangle of svg.querySelectorAll('rect')) {
				context.fillRect(
					Number(rectangle.getAttribute('x') ?? 0),
					Number(rectangle.getAttribute('y') ?? 0),
					Number(rectangle.getAttribute('width') ?? 0),
					Number(rectangle.getAttribute('height') ?? 0)
				);
			}
		}

		context.restore();
	};

	const createTicket = (label) => {
		const canvas = document.createElement('canvas');
		canvas.width = 384;
		canvas.height = 320;
		const context = canvas.getContext('2d', { willReadFrequently: true });
		if (!context) {
			throw new Error('El navegador no pudo preparar el ticket.');
		}

		context.fillStyle = '#fff';
		context.fillRect(0, 0, canvas.width, canvas.height);

		if (label.classList.contains('product-tire-label')) {
			const qr = label.querySelector('.product-tire-label-barcode .qr-code-svg');
			const barcode = label.querySelector('.product-tire-label-barcode .barcode-svg');
			drawWrappedText(context, labelText(label, '.product-tire-label-copy strong'), 26, 'bold 20px Arial', 368, 22, 1);
			drawWrappedText(context, labelText(label, '.product-tire-label-copy span'), 50, '16px Arial', 368, 19, 2);
			drawSvg(context, barcode, 20, 86, 344, 72);
			drawWrappedText(context, labelText(label, '.product-tire-label-barcode small'), 178, 'bold 18px Arial', 368, 20, 1);
			drawSvg(context, qr, 20, 194, 120, 120);
			drawWrappedText(context, labelText(label, '.product-tire-label-copy small'), 234, '14px Arial', 190, 18, 1, 270);
			drawWrappedText(context, labelText(label, '.product-tire-label-copy b'), 278, 'bold 26px Arial', 190, 28, 1, 270);
		} else {
			const qr = label.querySelector('.label-code-pair .qr-code-svg');
			const barcode = label.querySelector('.label-code-pair .barcode-svg');
			drawWrappedText(context, labelText(label, '.tire-label-brand'), 22, 'bold 18px Arial', 368, 20, 1);
			drawWrappedText(context, labelText(label, '.tire-label-name'), 43, 'bold 16px Arial', 368, 18, 2);
			drawWrappedText(context, labelText(label, '.tire-label-measure'), 81, '14px Arial', 368, 16, 1);
			drawSvg(context, barcode, 20, 96, 344, 72);
			drawWrappedText(context, labelText(label, '.tire-label-code'), 188, 'bold 17px Arial', 368, 18, 1);
			drawSvg(context, qr, 132, 196, 120, 120);
			drawWrappedText(context, labelText(label, '.tire-label-warehouse'), 306, '14px Arial', 90, 16, 1, 330);
		}

		const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
		const bytesPerRow = canvas.width / 8;
		const bitmap = new Uint8Array(bytesPerRow * canvas.height);
		for (let y = 0; y < canvas.height; y++) {
			for (let x = 0; x < canvas.width; x++) {
				const pixel = (y * canvas.width + x) * 4;
				const darkness = pixels[pixel] * 0.299 + pixels[pixel + 1] * 0.587 + pixels[pixel + 2] * 0.114;
				if (darkness < 160) {
					bitmap[y * bytesPerRow + Math.floor(x / 8)] |= 0x80 >> (x % 8);
				}
			}
		}

		const ticket = new Uint8Array(10 + bitmap.length + 2);
		ticket.set([0x1b, 0x40, 0x1d, 0x76, 0x30, 0x00, bytesPerRow, 0x00, canvas.height & 0xff, canvas.height >> 8]);
		ticket.set(bitmap, 10);
		ticket.set([0x0a, 0x0a], 10 + bitmap.length);
		return ticket;
	};

	if (!('serial' in navigator)) {
		setStatus('Este navegador no permite conectar directamente la impresora Bluetooth.');
		if (connectButton) {
			connectButton.disabled = true;
		}
	} else {
		connectButton?.addEventListener('click', async () => {
			connectButton.disabled = true;
			setStatus('Selecciona el puerto Bluetooth de Barcode+Printer, COM8. No se enviará nada al conectarlo.');
			try {
				const port = await navigator.serial.requestPort();
				await port.open({ baudRate: 9600 });
				serialPort = port;
				if (printButton) {
					printButton.disabled = false;
				}
				if (disconnectButton) {
					disconnectButton.disabled = false;
				}
				setStatus('Lector impresor conectado. Pulsa «Imprimir en la máquina» para enviar los tickets.');
			} catch (error) {
				const detail = error instanceof Error ? error.message : String(error);
				setStatus(error?.name === 'NotFoundError'
					? 'No se seleccionó ningún puerto Bluetooth.'
					: `No se pudo conectar con la máquina: ${detail}`);
			} finally {
				connectButton.disabled = serialPort !== null;
			}
		});
	}

	disconnectButton?.addEventListener('click', async () => {
		if (!serialPort) {
			return;
		}
		try {
			await serialPort.close();
			serialPort = null;
			if (printButton) {
				printButton.disabled = true;
			}
			disconnectButton.disabled = true;
			if (connectButton) {
				connectButton.disabled = false;
			}
			setStatus('Impresora desconectada.');
		} catch (error) {
			const detail = error instanceof Error ? error.message : String(error);
			setStatus(`No se pudo desconectar la máquina: ${detail}`);
		}
	});

	printButton?.addEventListener('click', async () => {
		if (!serialPort?.writable) {
			setStatus('Conecta primero la impresora integrada por Bluetooth.');
			return;
		}

		const labels = [...labelPage.querySelectorAll('.product-tire-label, .tire-label')];
		if (labels.length === 0) {
			setStatus('No hay tickets preparados para imprimir.');
			return;
		}

		printButton.disabled = true;
		let writer = null;
		try {
			writer = serialPort.writable.getWriter();
			for (let index = 0; index < labels.length; index++) {
				setStatus(`Enviando ticket ${index + 1} de ${labels.length} a Barcode+Printer…`);
				await writer.write(createTicket(labels[index]));
			}
			setStatus(`Se enviaron ${labels.length} ticket(s) al lector impresor.`);
		} catch (error) {
			const detail = error instanceof Error ? error.message : String(error);
			setStatus(`La impresora Bluetooth no aceptó el trabajo: ${detail}`);
		} finally {
			if (writer) {
				writer.releaseLock();
			}
			printButton.disabled = !serialPort?.writable;
		}
	});
}
