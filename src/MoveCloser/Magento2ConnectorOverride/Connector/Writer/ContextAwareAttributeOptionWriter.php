<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Writer;

use Webkul\Magento2Bundle\Connector\Writer\AttributeOptionWriter;

class ContextAwareAttributeOptionWriter extends AttributeOptionWriter
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $adminOptions = [];

    /**
     * POST odrzucony, bo opcję z tą etykietą admina założył inny PIM - PUT na jej id,
     * a zwrócone id write() zapisuje jako mapowanie.
     */
    protected function addAttributeOption(array $resource, $attributeCode)
    {
        $result = parent::addAttributeOption($resource, $attributeCode);

        if (!isset($result['error']) || isset($resource[self::AKENEO_ENTITY_NAME]['value'])) {
            return $result;
        }

        $existingId = $this->findAdminOptionId((string) $attributeCode, (string) $resource[self::AKENEO_ENTITY_NAME]['label']);
        if ($existingId === null) {
            return $result;
        }

        $resource[self::AKENEO_ENTITY_NAME]['value'] = $existingId;
        $updated = parent::addAttributeOption($resource, $attributeCode);

        return isset($updated['error']) ? $updated : $existingId;
    }

    private function findAdminOptionId(string $attributeCode, string $label): ?string
    {
        $this->adminOptions[$attributeCode] ??= $this->fetchAdminOptions($attributeCode);

        // Magento porównuje etykiety bez względu na wielkość liter
        $ids = [];
        foreach ($this->adminOptions[$attributeCode] as $option) {
            if (mb_strtolower(trim((string) ($option['label'] ?? ''))) === mb_strtolower($label)) {
                $ids[] = (string) $option['value'];
            }
        }

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Scope "all" zwraca etykiety admina - Webkul pobiera opcje w domyślnym store view.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAdminOptions(string $attributeCode): array
    {
        $url = str_replace('{attributeCode}', $attributeCode, $this->oauthClient->getApiUrlByEndpoint('attributeOption', 'all'));

        try {
            $this->oauthClient->fetch($url, null, 'GET', $this->jsonHeaders);
        } catch (\Exception) {
            return [];
        }

        return json_decode($this->oauthClient->getLastResponse(), true) ?: [];
    }
}
