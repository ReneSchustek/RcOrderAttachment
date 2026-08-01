<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Session;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Hält die Liste der vom Customer hochgeladenen, aber noch nicht zur Order
 * gehörenden Dateien in der Session.
 *
 * Die Session ist die einzige sinnvolle Quelle, weil die Order zu diesem
 * Zeitpunkt noch nicht existiert und der Customer sowohl Gast als auch
 * eingeloggt sein kann.
 *
 * Persistente Sichten (Cleanup, Mail-Versand) lesen NICHT von hier, sondern
 * aus `rc_order_attachment` (bzw. `media`).
 */
final class PendingUploadStore
{
    public const SESSION_KEY = 'rc_order_attachment.pending';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Nimmt eine hochgeladene Datei in die Session auf.
     *
     * Liefert `false`, wenn es keine Session gibt. Der Rückgabewert ist wichtig: Ohne Session
     * kann die Datei später keiner Bestellung zugeordnet werden -- sie läge als verwaistes
     * Medium herum, bis der Orphan-Cleanup sie entfernt. Vorher meldete der Upload in diesem
     * Fall trotzdem Erfolg, und der Kunde sah eine Datei, die nie ankommen konnte. Der Aufrufer
     * muss den Fehlschlag deshalb behandeln.
     */
    public function add(PendingUpload $upload): bool
    {
        $session = $this->session();
        if ($session === null) {
            return false;
        }

        $list = $this->loadList($session);
        $list[$upload->token] = $upload;
        $this->saveList($session, $list);

        return true;
    }

    public function remove(string $token): ?PendingUpload
    {
        $session = $this->session();
        if ($session === null) {
            return null;
        }

        $list = $this->loadList($session);
        $removed = $list[$token] ?? null;
        unset($list[$token]);
        $this->saveList($session, $list);

        return $removed;
    }

    public function get(string $token): ?PendingUpload
    {
        $session = $this->session();
        if ($session === null) {
            return null;
        }

        return $this->loadList($session)[$token] ?? null;
    }

    /**
     * @return array<string, PendingUpload>
     */
    public function all(): array
    {
        $session = $this->session();
        if ($session === null) {
            return [];
        }

        return $this->loadList($session);
    }

    public function clear(): void
    {
        $session = $this->session();
        $session?->remove(self::SESSION_KEY);
    }

    public function count(): int
    {
        return \count($this->all());
    }

    /**
     * Ob überhaupt eine Storefront-Session verfügbar ist. Unterscheidet
     * „Session vorhanden, aber leer" (Storefront, kein Upload) von „keine
     * Session" (Store-API/Headless/Admin-Bestellung) — dort existiert die
     * Confirm-Page-Upload-Möglichkeit gar nicht.
     */
    public function sessionAvailable(): bool
    {
        return $this->session() !== null;
    }

    public function totalBytes(): int
    {
        $total = 0;
        foreach ($this->all() as $upload) {
            $total += $upload->fileSize;
        }

        return $total;
    }

    /**
     * @return list<string>
     */
    public function getMediaIds(): array
    {
        return array_values(array_map(
            static fn (PendingUpload $upload): string => $upload->mediaId,
            $this->all()
        ));
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }

    /**
     * @return array<string, PendingUpload>
     */
    private function loadList(SessionInterface $session): array
    {
        $raw = $session->get(self::SESSION_KEY);
        if (!\is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $token => $payload) {
            if (!\is_string($token) || !\is_array($payload)) {
                continue;
            }
            $upload = PendingUpload::fromArray($payload);
            if ($upload === null) {
                continue;
            }
            $result[$token] = $upload;
        }

        return $result;
    }

    /**
     * @param array<string, PendingUpload> $list
     */
    private function saveList(SessionInterface $session, array $list): void
    {
        $serialized = [];
        foreach ($list as $token => $upload) {
            $serialized[$token] = $upload->jsonSerialize();
        }
        $session->set(self::SESSION_KEY, $serialized);
    }
}
