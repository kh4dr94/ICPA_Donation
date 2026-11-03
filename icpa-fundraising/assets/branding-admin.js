(function () {
    'use strict';
    const choose = document.getElementById('icpa-choose-banner');
    const clear = document.getElementById('icpa-clear-banner');
    const field = document.getElementById('icpa-hero_image_id');
    const preview = document.getElementById('icpa-banner-preview');
    if (!choose || !clear || !field || !preview || !window.wp || !wp.media) return;
    choose.addEventListener('click', function () {
        const frame = wp.media({ title: 'Choose donation page banner', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
        frame.on('select', function () {
            const image = frame.state().get('selection').first().toJSON();
            field.value = image.id;
            preview.replaceChildren();
            const thumbnail = document.createElement('img');
            thumbnail.src = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url;
            thumbnail.alt = '';
            thumbnail.style.maxWidth = '150px';
            thumbnail.style.height = 'auto';
            preview.append(thumbnail);
        });
        frame.open();
    });
    clear.addEventListener('click', function () { field.value = '0'; preview.replaceChildren(); });
}());
