<?php

declare(strict_types=1);

/**
 * Testy pominięcia produktu bez SKU w locale i zamiany \Error na wyjątek kroku. Potrzebują vendora
 * PIM (Akeneo + Webkul); klasy paczki idą z tego repo, nie z vendora PIM.
 *
 * Uruchomienie: php tests/sku-locale-selftest.php /sciezka/do/pim
 */

$pim = rtrim($argv[1] ?? '', '/');

if ('' === $pim || !is_file($pim . '/vendor/autoload.php')) {
    fwrite(STDERR, "Uzycie: php tests/sku-locale-selftest.php /sciezka/do/pim\n");
    exit(2);
}

require $pim . '/vendor/autoload.php';
require __DIR__ . '/../src/MoveCloser/Magento2ConnectorOverride/Connector/Writer/ContextAwareProductWriter.php';
require __DIR__ . '/../src/MoveCloser/Magento2ConnectorOverride/Step/ErrorReportingStep.php';

use Akeneo\Tool\Component\Batch\Item\ItemProcessorInterface;
use Akeneo\Tool\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Tool\Component\Batch\Item\ItemWriterInterface;
use Akeneo\Tool\Component\Batch\Job\JobParameters;
use Akeneo\Tool\Component\Batch\Model\JobExecution;
use Akeneo\Tool\Component\Batch\Model\JobInstance;
use Akeneo\Tool\Component\Batch\Model\StepExecution;
use MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareProductWriter;
use MoveCloser\Magento2ConnectorOverride\Step\ErrorReportingStep;
use Webkul\Magento2Bundle\Component\OAuthClient;
use Webkul\Magento2Bundle\Services\Magento2Connector;

$failed = [];

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failed;

    if ($expected === $actual) {
        printf("  ok   %s\n", $name);

        return;
    }

    $failed[] = $name;
    printf("  FAIL %s\n       oczekiwano %s, jest %s\n", $name, json_encode($expected), json_encode($actual));
}

function stepExecution(string $jobName): StepExecution
{
    $jobExecution = new JobExecution();
    $jobExecution->setJobInstance(new JobInstance('Magento 2 Connector', 'export', $jobName));
    $jobExecution->setJobParameters(new JobParameters(['filters' => ['data' => [], 'structure' => ['locales' => ['en_GB', 'pl_PL']]]]));

    return new StepExecution('product_model_variants_export', $jobExecution);
}

function setProperty(object $object, string $property, mixed $value): void
{
    (new ReflectionProperty($object, $property))->setValue($object, $value);
}

function call(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

/** @return list<string> */
function warnings(StepExecution $stepExecution): array
{
    return array_map(static fn ($warning): string => $warning->getReason(), $stepExecution->getWarnings()->toArray());
}

// konstruktor OAuthClient różni się między wersjami Webkul (token / login + hasło)
$http = new class() extends OAuthClient {
    public array $calls = [];

    public function __construct()
    {
        $this->hostName = 'http://magento.test/';
    }

    public function fetch($url, $payload, $method, $headers, $jsonEncode = true)
    {
        $this->calls[] = $method . ' ' . $url;
        $this->lastResponse = json_encode(['id' => count($this->calls), 'sku' => json_decode((string) $payload, true)['product']['sku'] ?? null]);

        return $this->lastResponse;
    }
};

$writer = (new ReflectionClass(ContextAwareProductWriter::class))->newInstanceWithoutConstructor();
$stepExecution = stepExecution('magento2_product_only_export');
setProperty($writer, 'stepExecution', $stepExecution);
setProperty($writer, 'oauthClient', $http);
setProperty($writer, 'defaultLocale', 'pl_PL');
setProperty($writer, 'defaultChannel', 'ecommerce');
setProperty($writer, 'attributeMappings', ['sku' => 'product_sku']);

$skuPl = [['locale' => 'pl_PL', 'scope' => null, 'data' => 'SKU-PL']];
$skuEn = [['locale' => 'en_GB', 'scope' => null, 'data' => 'SKU-EN']];

check('SKU z locale', 'SKU-PL', call($writer, 'resolvedSku', $skuPl, 'ecommerce', 'pl_PL', null));
check('brak SKU w locale', null, call($writer, 'resolvedSku', $skuPl, 'ecommerce', 'en_GB', null));
check('SKU "0" jak w vendorze: brak', null, call($writer, 'resolvedSku', [['locale' => 'pl_PL', 'scope' => null, 'data' => '0']], 'ecommerce', 'pl_PL', null));

$writer->write([['sku' => $skuEn, 'type_id' => 'simple', 'metadata' => ['identifier' => 'A-1']]]);
check('brak SKU w domyślnym locale: produkt pominięty bez HTTP', [], $http->calls);
check('brak SKU w domyślnym locale: licznik skipped', 1, $stepExecution->getSummaryInfo('skipped'));
check(
    'brak SKU w domyślnym locale: ostrzeżenie',
    ['Product "A-1" not exported in any store view: SKU attribute "product_sku" has no value for locale pl_PL.'],
    warnings($stepExecution)
);

setProperty($writer, 'lastResults', ['SKU-PL' => null]);
$sent = call($writer, 'addProduct', ['product' => ['sku' => 'SKU-PL', 'name' => 'n', 'type_id' => 'simple']], false, 'all');
$skip = ['identifier' => 'B-1', 'attribute' => 'product_sku', 'locale' => 'en_GB'];
$marked = ['product' => ['sku' => 'SKU-PL', 'type_id' => 'simple', '__skip_store_view' => $skip]];

check('oznaczony store view: wynik ostatniego zapisu SKU', $sent, call($writer, 'addProduct', $marked, false, 'lr_en'));
check('oznaczony store view: bez HTTP', 1, count($http->calls));
call($writer, 'addProduct', $marked, false, 'lr_en');
check(
    'oznaczony store view: jedno ostrzeżenie mimo dwóch przebiegów',
    'Product "B-1" not exported to store view "lr_en": SKU attribute "product_sku" has no value for locale en_GB.',
    warnings($stepExecution)[1] ?? null
);
check('oznaczony store view: bez duplikatu ostrzeżenia', 2, count(warnings($stepExecution)));
check(
    'oznaczony store view bez wcześniejszego zapisu: error zamiast null',
    ['error' => 'no SKU for store view "lr_en"'],
    call($writer, 'addProduct', ['product' => ['sku' => 'SKU-X', '__skip_store_view' => $skip]], false, 'lr_en')
);

$writer->write([]);
check('write() czyści wyniki z poprzedniej paczki', [], (new ReflectionProperty($writer, 'lastResults'))->getValue($writer));

// prawdziwe formatData() Webkula: bez mapowania url_key klucz powstaje z SKU, a tablica jako SKU wywraca iconv()
setProperty($writer, 'connectorService', (new ReflectionClass(Magento2Connector::class))->newInstanceWithoutConstructor());
setProperty($writer, 'downloadableProductService', null);
$writer->exportChannelCategory = false;

$product = static fn (array $sku, string $identifier): array => [
    'sku'               => $sku,
    'type_id'           => 'simple',
    'custom_attributes' => [],
    'metadata'          => ['identifier' => $identifier, 'unprocessed' => ['sku'], 'family' => null, 'categories' => []],
];
$urlKey = static fn (array $formatted): mixed => array_column($formatted['custom_attributes'] ?? [], 'value', 'attribute_code')['url_key'] ?? null;

$formatted = call($writer, 'formatData', $product($skuPl, 'C-1'), 'ecommerce', 'pl_PL', null);
check('formatData, SKU w locale: SKU', 'SKU-PL', $formatted['sku'] ?? null);
check('formatData, SKU w locale: bez znacznika', false, isset($formatted['__skip_store_view']));

$formatted = call($writer, 'formatData', $product($skuPl, 'C-1'), 'ecommerce', 'en_GB', null);
check('formatData, brak SKU w locale: SKU domyślnego store view', 'SKU-PL', $formatted['sku'] ?? null);
check('formatData, brak SKU w locale: url_key ze SKU, nie z tablicy', 'sku-pl', $urlKey($formatted));
check('formatData, brak SKU w locale: znacznik', ['identifier' => 'C-1', 'attribute' => 'product_sku', 'locale' => 'en_GB'], $formatted['__skip_store_view'] ?? null);

check(
    'formatData, brak SKU w domyślnym locale: sam znacznik',
    ['__skip_store_view' => ['identifier' => 'C-2', 'attribute' => 'product_sku', 'locale' => 'pl_PL']],
    call($writer, 'formatData', $product($skuEn, 'C-2'), 'ecommerce', 'pl_PL', null)
);

setProperty($writer, 'stepExecution', stepExecution('magento2_quick_export'));
check('quick export: pierwsza wartość niezależnie od locale', 'SKU-EN', call($writer, 'resolvedSku', $skuEn, 'ecommerce', 'pl_PL', null));

$step = (new ReflectionClass(ErrorReportingStep::class))->newInstanceWithoutConstructor();
setProperty($step, 'reader', new class() implements ItemReaderInterface {
    public function read()
    {
        return urlencode([]);
    }
});
setProperty($step, 'processor', new class() implements ItemProcessorInterface {
    public function process($item)
    {
        return $item;
    }
});
setProperty($step, 'writer', new class() implements ItemWriterInterface {
    public function write(array $items)
    {
    }
});

try {
    $step->doExecute(stepExecution('magento2_product_only_export'));
    check('\Error w kroku: wyjątek', RuntimeException::class, null);
} catch (\Throwable $e) {
    check('\Error w kroku: RuntimeException', RuntimeException::class, $e::class);
    check('\Error w kroku: oryginał w previous', TypeError::class, $e->getPrevious() ? $e->getPrevious()::class : null);
    check('\Error w kroku: klasa i treść w komunikacie', 1, preg_match('/^TypeError: urlencode\(\).*\(sku-locale-selftest\.php:\d+\)$/', $e->getMessage()));
}

if ([] !== $failed) {
    printf("\nNIEUDANE: %d\n", count($failed));
    exit(1);
}

echo "OK\n";
