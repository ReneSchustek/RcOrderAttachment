<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Session;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Hält in der Sitzung die Dateien, die der Kunde hochgeladen hat und die noch zu keiner Bestellung
 * gehören.
 *
 * Nur die Sitzung taugt dafür: Die Bestellung existiert noch nicht, und der Kunde kann Gast sein,
 * also ohne Konto, an das sich die Liste hängen ließe.
 *
 * Aufräumaufgaben und Mailversand lesen nicht von hier, sondern aus `rc_order_attachment` und
 * `media`.
 */
final class PendingUploadStore
{
    public const SESSION_KEY = 'rc_order_attachment.pending';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Liefert `false`, wenn es keine Sitzung gibt. Der Aufrufer muss das behandeln: Ohne Sitzung
     * lässt sich die Datei keiner Bestellung zuordnen, und ein gemeldeter Erfolg zeigte dem Kunden
     * eine Datei, die nie ankommt.
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

    // Die Hauptanfrage, weil eine Unteranfrage keine eigene Sitzung trägt.
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
