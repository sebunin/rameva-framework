<?php
/**
 * RAMEVA Framework 2
 *
 * @package    Rameva
 * @subpackage Services
 * @author     Rameva Development Team
 * @copyright  (C) Rameva LLC
 * @license    MIT License
 * @link       https://rameva.ru
 *
 * Класс Schema — расширяемый генератор микроразметки Schema.org в формате JSON-LD.
 *
 * Перенесён из RAMEVA Framework 1 (app/Core/Schema.php) с сохранением всего
 * функционала и с адаптацией к конфигурации второго фреймворка:
 * - builder-паттерн: create()/context()/type()/set()/add()/fromArray()/fromConfig()/build();
 * - нормализация значений: относительные URL -> абсолютные, даты -> ISO 8601,
 *   адрес -> PostalAddress (street/city/postal/country -> schema.org-имена);
 * - валидация обязательных полей по типу схемы (предупреждение в лог, без исключения);
 * - фабрики: webSite(), localBusiness(), article(), product(), organization(),
 *   person(), breadcrumb(), breadcrumbList(), webPage(), faqPage(), jsonLd(),
 *   renderMultiple();
 * - добавлено в RAMEVA 2: howTo() (пошаговые инструкции) и withSpeakable()
 *   (SpeakableSpecification для голосовых ассистентов и GEO-поиска).
 *
 * Примеры:
 * ```php
 * echo Schema::create('Article')->set('headline', 'Заголовок')->build();
 * echo Schema::article(['headline' => '...', 'author' => ['@type' => 'Person', 'name' => 'Иван']])->build();
 * echo Schema::renderMultiple([Schema::webSite(), Schema::organization(['name' => 'Rameva'])]);
 * ```
 *
 * Базовые данные для webSite()/localBusiness() берутся из необязательной секции
 * `schema` файла app/Config/App.php (в первой версии фреймворка — config->schema).
 */

declare(strict_types=1);

namespace System\Services;

use System\Helpers\Url;

// PHP Guard: Защита от прямого доступа
if (!defined('RAMEVA_CORE')) {
    http_response_code(403);
    exit;
}

class Schema
{
    /** @var array Данные схемы */
    private array $data = [];

    /** @var string Контекст Schema.org */
    private string $context = 'https://schema.org';

    /** @var array|null Кэш конфигурации приложения */
    private static ?array $appConfig = null;

    /** @var array Обязательные поля для разных типов схем */
    private const REQUIRED_FIELDS = [
        'LocalBusiness' => ['name', 'address'],
        'Article'       => ['headline', 'author', 'datePublished'],
        'Product'       => ['name', 'offers'],
        'WebSite'       => ['name', 'url'],
        'Organization'  => ['name'],
    ];

    /**
     * Создаёт новый экземпляр билдера.
     *
     * @param string|null $type Тип сущности Schema.org (необязательный, как в первой версии)
     */
    public static function create(?string $type = null): self
    {
        $builder = new self();

        if ($type !== null && $type !== '') {
            $builder->type($type);
        }

        return $builder;
    }

    /**
     * Устанавливает @context.
     */
    public function context(string $context = 'https://schema.org'): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Устанавливает @type (обязательно).
     */
    public function type(string $type): self
    {
        $this->data['@type'] = $type;
        return $this;
    }

    /**
     * Устанавливает значение поля (перезаписывает, если существует).
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $this->normalizeValue($key, $value);
        return $this;
    }

    /**
     * Добавляет значение в массив поля.
     */
    public function add(string $key, mixed $value): self
    {
        if (!isset($this->data[$key]) || !is_array($this->data[$key])) {
            $this->data[$key] = [];
        }

        $this->data[$key][] = $this->normalizeValue($key, $value);
        return $this;
    }

    /**
     * Загружает данные из конфигурации с нормализацией значений.
     */
    public function fromConfig(array $config): self
    {
        foreach ($config as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * Загружает данные из произвольного массива.
     */
    public function fromArray(array $data): self
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($this->data[$key]) && is_array($this->data[$key])) {
                // Для массивов (например, sameAs) — добавляем значения
                foreach ($value as $item) {
                    $this->add($key, $item);
                }
            } else {
                $this->set($key, $value);
            }
        }

        return $this;
    }

    /**
     * Возвращает данные схемы в виде массива.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Нормализует значения (автоматически обрабатывает URL и даты).
     */
    private function normalizeValue(string $key, mixed $value): mixed
    {
        if (in_array($key, ['url', 'image', 'logo'], true) && is_string($value)) {
            return self::normalizeUrlValue($value);
        }

        if ($key === 'address' && is_array($value)) {
            return $this->normalizeAddress($value);
        }

        if (in_array($key, ['datePublished', 'dateModified'], true) && is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('c', $timestamp); // ISO 8601
            }
        }

        return $value;
    }

    /**
     * Преобразует относительный URL в абсолютный.
     *
     * Домен берётся из конфигурации приложения (siteurl), а если он не задан —
     * из текущего запроса.
     */
    private static function normalizeUrlValue(string $url): string
    {
        if (preg_match('#^(https?://|data:)#i', $url)) {
            return $url;
        }

        $config = self::appConfig();
        $baseUrl = rtrim((string)($config['siteurl'] ?? ''), '/');

        if ($baseUrl === '') {
            $baseUrl = rtrim(Url::base(), '/');
        }

        return $baseUrl . '/' . ltrim($url, '/');
    }

    /**
     * Нормализует адрес для PostalAddress.
     *
     * Массив, который уже размечен схемой (@type) либо не содержит известных
     * ключей адреса, возвращается без изменений.
     */
    private function normalizeAddress(array $address): array
    {
        if (isset($address['@type'])) {
            return $address;
        }

        $knownKeys = ['street', 'city', 'postal', 'country'];

        if (empty(array_intersect($knownKeys, array_keys($address)))) {
            return $address;
        }

        $normalized = ['@type' => 'PostalAddress'];

        $mapping = [
            'street'  => 'streetAddress',
            'city'    => 'addressLocality',
            'postal'  => 'postalCode',
            'country' => 'addressCountry',
        ];

        foreach ($mapping as $sourceKey => $schemaKey) {
            if (isset($address[$sourceKey])) {
                $normalized[$schemaKey] = $address[$sourceKey];
            }
        }

        return $normalized;
    }

    /**
     * Возвращает конфигурацию приложения (с кэшированием на время запроса).
     */
    private static function appConfig(): array
    {
        if (self::$appConfig === null) {
            $configFile = APPPATH . '/Config/App.php';
            self::$appConfig = file_exists($configFile) ? (require $configFile) : [];
        }

        return self::$appConfig;
    }

    /**
     * Валидация схемы перед сборкой.
     *
     * Отсутствие обязательных полей не прерывает рендеринг: в режиме отладки
     * пишется предупреждение в журнал.
     */
    private function validate(): void
    {
        if (!isset($this->data['@type'])) {
            throw new \RuntimeException('Schema @type is required.');
        }

        $type = (string)$this->data['@type'];

        if (!isset(self::REQUIRED_FIELDS[$type])) {
            return;
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS[$type] as $field) {
            if (!isset($this->data[$field])) {
                $missing[] = $field;
            }
        }

        if (empty($missing)) {
            return;
        }

        $config = self::appConfig();
        if (empty($config['debug'])) {
            return;
        }

        $fields = implode(', ', $missing);
        LogService::write("Schema: для типа {$type} не заполнены обязательные поля: {$fields}", 'warning');
    }

    /**
     * Строит JSON-LD строку.
     */
    public function toJson(): string
    {
        $this->validate();

        $fullData = array_merge(['@context' => $this->context], $this->data);
        $json = json_encode($fullData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return is_string($json) ? $json : '';
    }

    /**
     * Строит полный <script> тег с JSON-LD.
     */
    public function build(): string
    {
        return '<script type="application/ld+json">' . PHP_EOL . $this->toJson() . PHP_EOL . '</script>' . PHP_EOL;
    }

    /**
     * Рендерит несколько схем в один HTML-блок.
     */
    public static function renderMultiple(array $schemas): string
    {
        $output = '';

        foreach ($schemas as $schema) {
            if ($schema instanceof self) {
                $output .= $schema->build() . PHP_EOL;
            } elseif (is_string($schema) && str_starts_with($schema, '<script')) {
                $output .= $schema . PHP_EOL;
            } elseif (is_array($schema)) {
                $output .= self::create()->fromArray($schema)->build() . PHP_EOL;
            }
        }

        return $output;
    }

    // ============================================================================
    // СТАТИЧЕСКИЕ ФАБРИКИ ДЛЯ ПОПУЛЯРНЫХ ТИПОВ СХЕМ
    // ============================================================================

    /**
     * Создаёт схему WebSite с базовыми данными из конфигурации.
     * Приоритет значений: $override > секция schema конфига > sitename/siteurl.
     */
    public static function webSite(array $override = []): self
    {
        $config = self::appConfig();

        $defaults = [
            'name' => $config['sitename'] ?? 'Default Site',
            'url'  => $config['siteurl'] ?? Url::base(),
        ];

        // Поля из секции schema, релевантные для типа WebSite
        $schemaConfig = [];
        $appSchema = $config['schema'] ?? [];
        if (is_array($appSchema)) {
            foreach (['description', 'image', 'inLanguage', 'potentialAction'] as $field) {
                if (isset($appSchema[$field])) {
                    $schemaConfig[$field] = $appSchema[$field];
                }
            }
        }

        return self::create()
            ->type('WebSite')
            ->fromArray(array_merge($defaults, $schemaConfig, $override));
    }

    /**
     * Создаёт схему LocalBusiness из конфигурации.
     */
    public static function localBusiness(array $override = []): self
    {
        $builder = self::create()->type('LocalBusiness');

        $appSchema = self::appConfig()['schema'] ?? [];
        if (!empty($appSchema) && is_array($appSchema)) {
            $builder->fromConfig($appSchema);
        }

        if (!empty($override)) {
            $builder->fromArray($override);
        }

        return $builder;
    }

    /**
     * Создаёт схему Article.
     */
    public static function article(array $data = []): self
    {
        return self::create()
            ->type('Article')
            ->fromArray($data);
    }

    /**
     * Создаёт схему Product.
     *
     * Если указана цена, но нет offers — предложение собирается автоматически.
     */
    public static function product(array $data): self
    {
        $builder = self::create()->type('Product');

        if (isset($data['price']) && !isset($data['offers'])) {
            $data['offers'] = [
                '@type'         => 'Offer',
                'price'         => $data['price'],
                'priceCurrency' => $data['priceCurrency'] ?? 'RUB',
                'availability'  => 'https://schema.org/InStock',
            ];
            unset($data['price'], $data['priceCurrency']);
        }

        return $builder->fromArray($data);
    }

    /**
     * Создаёт схему BreadcrumbList из списка элементов.
     *
     * @param array<int, array{name: string, url?: string}> $items
     */
    public static function breadcrumb(array $items): self
    {
        $itemListElement = [];

        foreach ($items as $position => $item) {
            $itemListElement[] = [
                '@type'    => 'ListItem',
                'position' => $position + 1,
                'name'     => $item['name'] ?? '',
            'item'     => isset($item['url']) ? self::normalizeUrlValue((string)$item['url']) : null,
            ];
        }

        return self::create()
            ->type('BreadcrumbList')
            ->set('itemListElement', $itemListElement);
    }

    /**
     * Фабрика BreadcrumbList из готового массива данных.
     *
     * Формат: ['itemListElement' => [['position' => 1, 'name' => 'Главная', 'item' => 'https://...'], ...]]
     */
    public static function breadcrumbList(array $data = []): self
    {
        return self::create()->type('BreadcrumbList')->fromArray($data);
    }

    /**
     * Фабрика Organization — общая информация о компании (логотип, контакты, адрес).
     */
    public static function organization(array $data = []): self
    {
        return self::create()->type('Organization')->fromArray($data);
    }

    /**
     * Фабрика WebPage — описание конкретной страницы (name, description, breadcrumb).
     */
    public static function webPage(array $data = []): self
    {
        return self::create()->type('WebPage')->fromArray($data);
    }

    /**
     * Фабрика FAQPage.
     *
     * Поддерживает оба формата данных:
     * - список вопросов и ответов: [['question' => '...', 'answer' => '...'], ...];
     * - готовый массив Schema.org: ['mainEntity' => [['@type' => 'Question', ...]]].
     */
    public static function faqPage(array $data = []): self
    {
        $builder = self::create()->type('FAQPage');

        if (isset($data['mainEntity'])) {
            return $builder->fromArray($data);
        }

        $mainEntity = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mainEntity[] = [
                '@type'          => 'Question',
                'name'           => $item['question'] ?? ($item['name'] ?? ''),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $item['answer'] ?? ($item['text'] ?? ''),
                ],
            ];
        }

        return $builder->set('mainEntity', $mainEntity);
    }

    /**
     * Фабрика Person — автор или контактное лицо.
     */
    public static function person(array $data = []): self
    {
        return self::create()->type('Person')->fromArray($data);
    }

    /**
     * Фабрика HowTo — пошаговая инструкция для расширенных сниппетов.
     *
     * @param string $name Название инструкции
     * @param array<int, string|array{name?: string, text?: string, url?: string}> $steps Шаги
     * @param array $custom Дополнительные поля схемы
     */
    public static function howTo(string $name, array $steps, array $custom = []): self
    {
        $builder = self::create()->type('HowTo');
        $builder->set('name', $name);

        $stepList = [];
        $position = 1;

        foreach ($steps as $step) {
            if (is_array($step)) {
                $stepList[] = array_merge([
                    '@type'    => 'HowToStep',
                    'position' => $position,
                ], $step);
            } else {
                $stepList[] = [
                    '@type'    => 'HowToStep',
                    'position' => $position,
                    'text'     => (string)$step,
                ];
            }

            $position++;
        }

        $builder->set('step', $stepList);

        foreach ($custom as $key => $value) {
            $builder->set($key, $value);
        }

        return $builder;
    }

    /**
     * Добавляет спецификацию Speakable (для голосовых ассистентов и GEO-поиска).
     *
     * @param array<int, string> $cssSelectors CSS-селекторы озвучиваемых блоков, например ['#headline', '.summary']
     */
    public function withSpeakable(array $cssSelectors): self
    {
        $this->set('speakable', [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => $cssSelectors,
        ]);

        return $this;
    }

    /**
     * Утилита: сборка готового <script> тега из произвольного массива JSON-LD.
     */
    public static function jsonLd(array $data = []): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return '<script type="application/ld+json">' . ($json === false ? '{}' : $json) . '</script>';
    }
}
