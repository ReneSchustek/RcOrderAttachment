import template from './rc-order-attachment-list.html.twig';

const { Component, Data, Mixin } = Shopware;
const { Criteria } = Data;

/**
 * Listet die Customer-Uploads (rc_order_attachment) einer Bestellung im
 * Admin-Order-Detail und bietet je Anhang einen Download über den
 * ACL-geschützten Admin-Endpoint `/api/_action/rc-order-attachment/{id}/download`.
 *
 * Read-only: Hochladen bleibt der Storefront-Confirm-Page vorbehalten, Löschen
 * dem Retention-/Orphan-Cleanup. Die Card dient allein der Auftragsbearbeitung
 * (Mitarbeiter kommt an das Kundendokument, ohne die Bestätigungs-Mail zu suchen).
 */
Component.register('rc-order-attachment-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    props: {
        orderId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            attachments: null,
            isLoading: false,
            downloadingIds: [],
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('rc_order_attachment');
        },

        criteria() {
            const criteria = new Criteria(1, 50);
            criteria.addFilter(Criteria.equals('orderId', this.orderId));
            criteria.addAssociation('media');
            criteria.addSorting(Criteria.sort('createdAt', 'ASC'));

            return criteria;
        },

        columns() {
            return [
                { property: 'originalFileName', label: 'rc-order-attachment.list.columnName', primary: true },
                { property: 'fileSize', label: 'rc-order-attachment.list.columnSize' },
                { property: 'mimeType', label: 'rc-order-attachment.list.columnType' },
                { property: 'createdAt', label: 'rc-order-attachment.list.columnUploaded' },
            ];
        },

        dateFilter() {
            return Shopware.Filter.getByName('date');
        },

        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        loginService() {
            return Shopware.Service('loginService');
        },
    },

    watch: {
        orderId() {
            this.loadAttachments();
        },
    },

    created() {
        this.loadAttachments();
    },

    methods: {
        loadAttachments() {
            if (!this.orderId) {
                return Promise.resolve();
            }

            // Sequenz-Guard: Wechselt die orderId, während eine Suche noch läuft,
            // darf eine langsamere frühere Antwort die neuere nicht überschreiben.
            const seq = (this._loadSeq = (this._loadSeq || 0) + 1);
            this.isLoading = true;

            return this.repository.search(this.criteria, Shopware.Context.api)
                .then((result) => {
                    if (seq !== this._loadSeq) {
                        return;
                    }
                    this.attachments = result;
                })
                .catch(() => {
                    if (seq !== this._loadSeq) {
                        return;
                    }
                    this.attachments = null;
                    this.createNotificationError({
                        message: this.$t('rc-order-attachment.list.loadError'),
                    });
                })
                .finally(() => {
                    if (seq === this._loadSeq) {
                        this.isLoading = false;
                    }
                });
        },

        formatFileSize(bytes) {
            const size = Number(bytes);
            if (!Number.isFinite(size) || size <= 0) {
                return '0 KB';
            }
            if (size < 1024 * 1024) {
                return `${(size / 1024).toFixed(1)} KB`;
            }

            return `${(size / (1024 * 1024)).toFixed(1)} MB`;
        },

        isDownloading(id) {
            return this.downloadingIds.includes(id);
        },

        onDownload(item) {
            if (this.isDownloading(item.id)) {
                return;
            }
            this.downloadingIds = [...this.downloadingIds, item.id];

            this.httpClient.get(`/_action/rc-order-attachment/${item.id}/download`, {
                responseType: 'blob',
                headers: {
                    Authorization: `Bearer ${this.loginService.getToken()}`,
                },
            }).then((response) => {
                this.triggerBrowserDownload(response.data, item);
            }).catch(() => {
                this.createNotificationError({
                    message: this.$t('rc-order-attachment.list.downloadError'),
                });
            }).finally(() => {
                this.downloadingIds = this.downloadingIds.filter((id) => id !== item.id);
            });
        },

        triggerBrowserDownload(blobData, item) {
            const blob = new Blob([blobData], {
                type: item.mimeType || 'application/octet-stream',
            });
            const objectUrl = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = item.originalFileName;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(objectUrl);
        },
    },
});
