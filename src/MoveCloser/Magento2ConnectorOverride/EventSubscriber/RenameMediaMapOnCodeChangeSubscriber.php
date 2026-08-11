<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\EventSubscriber;

use Akeneo\Pim\Enrichment\Component\Product\Model\ProductInterface;
use Akeneo\Pim\Enrichment\Component\Product\Model\ProductModelInterface;
use Akeneo\Tool\Component\StorageUtils\StorageEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Moves the media map onto the new identifier when a product (or product model) is renamed.
 *
 * The map is keyed by sku, so a rename would strand every row under the old one. The writer then
 * sees an untracked product, deliberately refuses to adopt gallery images by name - the guard that
 * protects manually uploaded images - and uploads everything again, doubling the Magento gallery.
 *
 * The old identifier is read in PRE_SAVE, while the row still holds it, and the rename is applied in
 * POST_SAVE so a failed save leaves the map untouched. Akeneo dispatches both per entity, also
 * inside a bulk save, so imports and mass edits are covered as well.
 *
 * @author MoveCloser
 */
class RenameMediaMapOnCodeChangeSubscriber implements EventSubscriberInterface
{
    private const MAP_TABLE = 'wk_magento2_media_mapping';

    /**
     * Renames detected in PRE_SAVE, keyed by spl object id: [old code, new code].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $pending = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StorageEvents::PRE_SAVE => 'rememberPreviousCode',
            StorageEvents::POST_SAVE => 'renameMapRows',
        ];
    }

    public function rememberPreviousCode(GenericEvent $event): void
    {
        $subject = $event->getSubject();

        if ($subject instanceof ProductInterface) {
            // Products are keyed by uuid - AbstractProduct::getId() throws on purpose.
            $oldCode = $this->connection->fetchOne(
                'SELECT identifier FROM pim_catalog_product WHERE uuid = UUID_TO_BIN(:uuid)',
                ['uuid' => $subject->getUuid()->toString()]
            );
            $newCode = (string) $subject->getIdentifier();
        } elseif ($subject instanceof ProductModelInterface) {
            if (null === $subject->getId()) {
                return;
            }

            $oldCode = $this->connection->fetchOne(
                'SELECT code FROM pim_catalog_product_model WHERE id = :id',
                ['id' => $subject->getId()]
            );
            $newCode = (string) $subject->getCode();
        } else {
            return;
        }

        if (!is_string($oldCode) || '' === $oldCode || $oldCode === $newCode) {
            return;
        }

        $this->pending[spl_object_id($subject)] = [$oldCode, $newCode];
    }

    public function renameMapRows(GenericEvent $event): void
    {
        $subject = $event->getSubject();
        $key = spl_object_id($subject);

        if (!isset($this->pending[$key])) {
            return;
        }

        [$oldCode, $newCode] = $this->pending[$key];
        unset($this->pending[$key]);

        // A map already sitting on the new sku would break the unique key; it belongs to whatever
        // used that identifier before, so it is dropped rather than merged.
        $this->connection->executeStatement(
            'DELETE FROM ' . self::MAP_TABLE . ' WHERE sku = :sku',
            ['sku' => $newCode]
        );

        $this->connection->executeStatement(
            'UPDATE ' . self::MAP_TABLE . ' SET sku = :new WHERE sku = :old',
            ['new' => $newCode, 'old' => $oldCode]
        );
    }
}
