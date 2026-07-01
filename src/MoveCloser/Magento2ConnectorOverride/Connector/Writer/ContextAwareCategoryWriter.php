<?php

namespace MoveCloser\Magento2ConnectorOverride\Connector\Writer;

use Webkul\Magento2Bundle\Connector\Writer\CategoryWriter;

class ContextAwareCategoryWriter extends CategoryWriter
{
    /**
     * Zwraca root kategorię Magento w kontekście sklepu właściwego dla kanału z jobu.
     *
     * Webkul wywołuje tu endpoint bez scope sklepu → Magento zwraca globalny default
     * (zawsze Dr Irena Eris). Nadpisanie przekazuje konkretny store view code
     * dopasowany do kanału Akeneo z parametrów jobu.
     *
     * @throws \RuntimeException gdy brak wpisu storeMapping dla bieżącego kanału
     * @return array{id: int, parent_id: int}|array{error: mixed}
     */
    protected function getDefaultStoreCategory(): array
    {
        $storeViewCode = $this->resolveStoreViewCodeForChannel();

        $url = $this->oauthClient->getApiUrlByEndpoint('categories', $storeViewCode);
        $url = strstr($url, '?', true) . '?depth=0';

        try {
            $this->oauthClient->fetch($url, null, 'GET', $this->jsonHeaders);
            $results = json_decode($this->oauthClient->getLastResponse(), true);

            return $results;
        } catch (\Exception $e) {
            $lastResponse = json_decode($this->oauthClient->getLastResponse(), true);

            return ['error' => $lastResponse];
        }
    }

    /**
     * Zwraca kod store view Magento pasujący do kanału Akeneo bieżącego jobu.
     *
     * Pomija klucz `allStoreView` (to sentinel konwertowany na 'all' w API — dałby
     * ten sam globalny default co oryginalna implementacja Webkul).
     *
     * @throws \RuntimeException gdy żaden wpis nie pasuje do kanału
     */
    private function resolveStoreViewCodeForChannel(): string
    {
        $channelCode = $this->parameters['filters']['structure']['scope'] ?? null;

        // Akeneo przechowuje scope jako array (np. ['ecommerce']) lub string — normalizujemy
        if (is_array($channelCode)) {
            $channelCode = $channelCode[0] ?? null;
        }

        foreach ($this->storeMapping as $storeViewCode => $mapping) {
            if ($storeViewCode === self::DEFAULT_STORE_VIEW_CODE) {
                continue;
            }

            if (isset($mapping['channel']) && $mapping['channel'] === $channelCode) {
                return $storeViewCode;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'ContextAwareCategoryWriter: brak wpisu storeMapping dla kanału "%s". '
                . 'Uzupełnij konfigurację w wk_magento2_credentials_mapping.',
                $channelCode ?? '(null)'
            )
        );
    }

    /**
     * Neutralizuje webkulowy fallback, który przy błędzie tworzenia kategorii
     * (np. „URL key already exists") pobiera całe drzewo Magento i przypisuje
     * mapowania po NAZWIE (url-key slug). Gdy nazwy kategorii się powtarzają
     * (Pharmaceris: wiele „Pielęgnacja"/„Oczyszczanie"), różne kategorie Magento
     * trafiają na ten sam kod Akeneo i nadpisują poprawne mapowania błędnym,
     * jednoimiennym id — psując przypięcie produktów do właściwej kategorii.
     *
     * Źródłem prawdy jest mapowanie po ścieżce (magento2:reconcile-category-mappings),
     * więc ten fallback celowo nic nie robi — pojedyncza nieutworzona kategoria
     * daje warning, ale nie zatruwa pozostałych mapowań.
     */
    protected function getCategoriesAndAddMappings()
    {
        // intentionally a no-op — see method docblock
    }
}
