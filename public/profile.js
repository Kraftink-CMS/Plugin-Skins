const maxPngBytes = 32_768;

function validDimensions(kind, width, height) {
    return kind === 'skin'
        ? width === 64 && (height === 64 || height === 32)
        : (width === 64 && height === 32) || (width === 22 && height === 17);
}

async function validPng(file, kind) {
    if (file.size < 24 || file.size > maxPngBytes) {
        return false;
    }

    const header = new DataView(await file.slice(0, 24).arrayBuffer());

    return header.getUint32(0) === 0x89504e47 && header.getUint32(4) === 0x0d0a1a0a &&
        header.getUint32(8) === 13 && header.getUint32(12) === 0x49484452 &&
        validDimensions(kind, header.getUint32(16), header.getUint32(20));
}

function drawFace(context, image, { base, overlay, x, y, width, height, depth, mirror }, back) {
    if (mirror) {
        context.save();
        context.translate(2 * x + width, 0);
        context.scale(-1, 1);
    }

    for (const [u, v] of overlay ? [base, overlay] : [base]) {
        const faceOffset = back ? depth + width : 0;
        context.drawImage(image, u + depth + faceOffset, v + depth, width, height, x, y, width, height);
    }

    if (mirror) {
        context.restore();
    }
}

function drawSkin(canvas, image, model, back) {
    const context = canvas.getContext('2d');
    const modern = image.naturalHeight === 64;
    const arm = modern && model === 'slim' ? 3 : 4;
    const leftArmX = 8 - arm;
    const faces = [
        { base: [0, 0], overlay: [32, 0], x: 8, y: 0, width: 8, height: 8, depth: 8 },
        { base: [16, 16], overlay: modern ? [16, 32] : null, x: 8, y: 8, width: 8, height: 12, depth: 4 },
        { base: [40, 16], overlay: modern ? [40, 32] : null, x: back ? 16 : leftArmX, y: 8, width: arm, height: 12, depth: 4 },
        {
            base: modern ? [32, 48] : [40, 16], overlay: modern ? [48, 48] : null,
            x: back ? leftArmX : 16, y: 8, width: arm, height: 12, depth: 4, mirror: !modern,
        },
        { base: [0, 16], overlay: modern ? [0, 32] : null, x: back ? 12 : 8, y: 20, width: 4, height: 12, depth: 4 },
        {
            base: modern ? [16, 48] : [0, 16], overlay: modern ? [0, 48] : null,
            x: back ? 8 : 12, y: 20, width: 4, height: 12, depth: 4, mirror: !modern,
        },
    ];

    context.clearRect(0, 0, canvas.width, canvas.height);
    for (const face of faces) {
        drawFace(context, image, face, back);
    }
}

function drawCape(canvas, image) {
    const context = canvas.getContext('2d');
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 1, 1, canvas.width, canvas.height, 0, 0, canvas.width, canvas.height);
}

function setupPreview(root, preview) {
    const kind = preview.dataset.skinsPreview;
    const input = root.querySelector(`#skins-${kind}`);
    const remove = root.querySelector(`#skins-remove-${kind}`);
    const variant = root.querySelector('#skins-variant');
    const original = preview.querySelector('[data-skins-original]');
    const render = preview.querySelector('[data-skins-render]');
    const state = preview.querySelector('[data-skins-state]');
    if (!preview.querySelector('canvas')?.getContext('2d')) {
        return;
    }
    let revision = 0;

    function show(label, visible) {
        render.hidden = !visible;
        if (original) {
            original.hidden = true;
        }
        state.textContent = preview.dataset[label];
    }

    async function refresh() {
        const request = ++revision;
        const file = input?.files?.[0];
        const saved = !remove?.checked && preview.dataset.saved;
        const source = saved || preview.dataset.default;
        const label = file ? 'draftLabel' : saved ? 'savedLabel' : source ? 'defaultLabel' : 'emptyLabel';
        const model = label === 'defaultLabel'
            ? preview.dataset.defaultModel
            : (variant?.value ?? preview.dataset.model);
        let objectUrl;

        try {
            if (file && !await validPng(file, kind)) {
                if (request === revision) {
                    show('invalidLabel', false);
                }
                return;
            }
            if (file || source) {
                const image = source === original?.getAttribute('src') && !file ? original : new Image();
                if (image !== original) {
                    image.src = file ? (objectUrl = URL.createObjectURL(file)) : source;
                }
                await image.decode();
                if (request !== revision) {
                    return;
                }
                if (!validDimensions(kind, image.naturalWidth, image.naturalHeight)) {
                    show('invalidLabel', false);
                    return;
                }
                if (kind === 'skin') {
                    drawSkin(render.querySelector('[data-skins-front]'), image, model, false);
                    drawSkin(render.querySelector('[data-skins-back]'), image, model, true);
                } else {
                    drawCape(render, image);
                }
            }
            show(label, Boolean(file || source));
        } catch {
            if (request === revision) {
                show('invalidLabel', false);
            }
        } finally {
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }
        }
    }

    input?.addEventListener('change', () => {
        if (input.files.length && remove) {
            remove.checked = false;
        }
        refresh();
    });
    remove?.addEventListener('change', () => {
        if (remove.checked && input) {
            input.value = '';
        }
        refresh();
    });
    if (kind === 'skin') {
        variant?.addEventListener('change', refresh);
    }
    refresh();
}

for (const root of document.querySelectorAll('[data-skins-profile]')) {
    for (const preview of root.querySelectorAll('[data-skins-preview]')) {
        setupPreview(root, preview);
    }
}
