import RcOrderAttachmentUploadPlugin from './plugin/rc-order-attachment-upload/rc-order-attachment-upload.plugin';

// Registriert das Upload-Plugin auf der Confirm-Page.
// Selector greift auf das Twig-Card-Element mit data-Attribut.
const PluginManager = window.PluginManager;

PluginManager.register(
    'RcOrderAttachmentUpload',
    RcOrderAttachmentUploadPlugin,
    '[data-rc-order-attachment-upload]'
);
