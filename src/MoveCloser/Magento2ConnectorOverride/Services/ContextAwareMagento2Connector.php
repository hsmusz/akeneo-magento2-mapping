<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Services;

use Webkul\Magento2Bundle\Services\Magento2Connector;

/**
 * Connector service override aligning url_key generation with Magento.
 *
 * @author MoveCloser
 */
class ContextAwareMagento2Connector extends Magento2Connector
{
    /**
     * {@inheritdoc}
     *
     * Vanilla replaces every non-alphanumeric character with its OWN hyphen, while Magento collapses
     * a whole run into one (`#[^0-9a-z]+#i` in \Magento\Framework\Filter\TranslitUrl). A name holding
     * "™" or "+" therefore reaches Magento as "med--do", gets rewritten to "med-do" on save, and the
     * product is rejected with "URL key for specified store already exists" once another product owns
     * that collapsed key. Collapsing here makes the key the PIM sends the key Magento actually stores.
     */
    public function formatUrlKey($string)
    {
        $formatted = parent::formatUrlKey($string);

        return trim((string) preg_replace('/-+/', '-', $formatted), '-');
    }
}
