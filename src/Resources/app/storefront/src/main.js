import RcOrderAttachmentUploadPlugin from './plugin/rc-order-attachment-upload/rc-order-attachment-upload.plugin';

// Das Upload-Plugin startet nur auf Seiten mit diesem Attribut, also auf der Bestätigungsseite,
// wenn die Vorlage die Upload-Box zeichnet.
const PluginManager = window.PluginManager;

PluginManager.register(
    'RcOrderAttachmentUpload',
    RcOrderAttachmentUploadPlugin,
    '[data-rc-order-attachment-upload]'
);
