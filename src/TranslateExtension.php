<?php

namespace Bolt\Extension\Animal\Translate;

use Bolt\Events\HydrationEvent;
use Bolt\Events\StorageEvent;
use Bolt\Events\StorageEvents;
use Bolt\Extension\SimpleExtension;
use Bolt\Storage\Entity\Content;
use Bolt\Storage\Field\Collection\RepeatingFieldCollection;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

/**
 * Translate extension class.
 *
 * @author Svante Richter <svante.richter@gmail.com>
 */
class TranslateExtension extends SimpleExtension
{
    /** @var string */
    protected $localeSlug;

    /**
     * @inheritdoc
     */
    protected function registerServices(Application $app)
    {
        $this->registerTranslateServices($app);
        $this->registerOverrides($app);

        $app->before([$this, 'before']);

        // Set default localeSlug in the event before() is not called, e.g. a 404
        $config = $this->getConfig();
        $this->localeSlug = array_column($config['locales'], 'slug')[0];
    }

    /**
     * Before handler that sets the localeSlug for future use and sets the
     * locales global in twig.
     *
     * @param Request     $request
     * @param Application $app
     */
    public function before(Request $request, Application $app)
    {
        $config = $this->getConfig();
        $defaultSlug = array_column($config['locales'], 'slug')[0];
        $localeSlug = $request->get('_locale', $defaultSlug);

        if (isset($config['locales'][$localeSlug])) {
            $this->localeSlug = $config['locales'][$localeSlug]['slug'];
        } elseif (in_array($localeSlug, array_column($config['locales'], 'slug'))) {
            $this->localeSlug = $localeSlug;
        }
        $this->registerTwigGlobal($app);
    }

    /**
     * {@inheritdoc}
     */
    public function getServiceProviders()
    {
        return [
            $this,
            new Provider\FieldProvider(),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {
        return [
            'templates' => ['position' => 'prepend', 'namespace' => 'bolt'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigFunctions()
    {
        return [
            'localeswitcher' => ['localeSwitcher', ['is_variadic' => true]],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        $parentEvents = parent::getSubscribedEvents();
        $localEvents = [
            StorageEvents::PRE_HYDRATE => [
                ['preHydrate', 0],
            ],
            StorageEvents::POST_HYDRATE => [
                ['postHydrate', 0],
            ],
            StorageEvents::PRE_SAVE => [
                ['preSave', 0],
            ],
            StorageEvents::POST_SAVE => [
                ['postSave', 0],
            ],
        ];

        return $parentEvents + $localEvents;
    }

    /**
     * StorageEvents::PRE_HYDRATE event callback.
     *
     * @param HydrationEvent $event
     */
    public function preHydrate(HydrationEvent $event)
    {
        $app = $this->getContainer();
        $request = $app['request_stack']->getCurrentRequest();
        if ($request === null) {
            return;
        }

        /** @var Content $entity */
        $entity = $event->getArgument('entity');
        $subject = $event->getSubject();

        if (!$entity instanceof Content || $request->query->getBoolean('no_locale_hydrate')) {
            return;
        }

        $contentTypeName = $entity->getContenttype();
        $contentType = $app['config']->get('contenttypes/' . $contentTypeName);

        if (isset($subject[$this->localeSlug . '_data'])) {
            $localeData = json_decode($subject[$this->localeSlug . '_data'], true);
            foreach ($localeData as $key => $value) {
                if ($contentType['fields'][$key]['type'] !== 'repeater') {
                    $subject[$key] = is_array($value) ? json_encode($value) : $value;
                }
            }
        }
    }

    /**
     * StorageEvents::POST_HYDRATE event callback.
     *
     * @param HydrationEvent $event
     */
    public function postHydrate(HydrationEvent $event)
    {
        $app = $this->getContainer();
        $request = $app['request_stack']->getCurrentRequest();
        if ($request === null) {
            return;
        }

        /** @var Content $subject */
        $subject = $event->getSubject();
        if (!$subject instanceof Content || $request->query->getBoolean('no_locale_hydrate')) {
            return;
        }

        $contentTypeName = $subject->getContenttype();
        $contentType = $app['config']->get('contenttypes/' . $contentTypeName);

        if (!isset($subject[$this->localeSlug . '_data'])) {
            return;
        }
        $localeData = json_decode($subject[$this->localeSlug . '_data'], true);
        foreach ($localeData as $key => $value) {
            if ($contentType['fields'][$key]['type'] !== 'repeater') {
                continue;
            }
            /** @var RepeatingFieldCollection[] $subject */
            $subject[$key]->clear();
            foreach ($value as $subValue) {
                $subject[$key]->addFromArray($subValue);
            }
        }
    }

    /**
     * StorageEvents::PRE_SAVE event callback.
     *
     * @param StorageEvent $event
     */
    public function preSave(StorageEvent $event)
    {
        $app = $this->getContainer();
        $contentType = $app['config']->get('contenttypes/' . $event->getContentType());
        $translatableFields = $this->getTranslatableFields($contentType['fields']);
        /** @var Content $record */
        $record = $event->getContent();
        $values = $record->serialize();
        $localeValues = [];

        if (empty($translatableFields)) {
            return;
        }

        $config = $this->getConfig();
        $record->set($this->localeSlug . '_slug', $values['slug']);
        if ($values['locale'] == array_keys($config['locales'])[0]) {
            $record->set($this->localeSlug . '_data', '[]');

            return;
        }

        if ($values['id']) {
            /** @var Content $defaultContent */
            $defaultContent = $app['query']->getContent(
                $event->getContentType(),
                ['id' => $values['id'], 'returnsingle' => true]
            );
        }
        foreach ($translatableFields as $field) {
            $localeValues[$field] = $values[$field];
            if ($values['id']) {
                $record->set($field, $defaultContent->get($field));
            } else {
                $record->set($field, '');
            }
        }
        $localeJson = json_encode($localeValues);
        $record->set($this->localeSlug . '_data', $localeJson);
    }

    /**
     * StorageEvents::POST_SAVE event callback.
     *
     * @param StorageEvent $event
     */
    public function postSave(StorageEvent $event)
    {
        $subject = $event->getSubject();
        if (get_class($subject) !== "Bolt\Storage\Entity\Content") {
            return;
        }

        $localeSlug = $this->localeSlug;

        if (isset($subject[$localeSlug . '_data'])) {
            $localeData = json_decode($subject[$localeSlug . '_data']);
            foreach ($localeData as $key => $value) {
                $subject->set($key, $value);
            }
        }
    }

    /**
     * Register translate services/values on the app container
     *
     * @param Application $app
     */
    private function registerTranslateServices(Application $app)
    {
        $app['translate'] = $app->share(
            function () {
                return $this;
            }
        );
        $app['translate.config'] = $app->share(
            function () {
                return $this->getConfig();
            }
        );
        $app['translate.slug'] = $app->share(
            function () {
                return $this->localeSlug;
            }
        );
    }

    /**
     * Register overrides for bolt's services
     *
     * @param Application $app
     */
    private function registerOverrides(Application $app)
    {
        $this->app['storage.legacy'] = $app->extend(
            'storage.legacy',
            function ($storage) use ($app) {
                return new Storage\Legacy($app);
            }
        );

        $app['controller.frontend'] = $app->share(
            function ($app) {
                $frontend = new Frontend\LocalizedFrontend();
                $frontend->connect($app);

                return $frontend;
            }
        );
        if ($this->app['translate.config']['menu_override']) {
            $app['menu'] = $app->share(
                function ($app) {
                    return new Frontend\LocalizedMenuBuilder($app);
                }
            );
        }

        $config = $this->getConfig();
        $app['schema.content_tables'] = $app->extend(
            'schema.content_tables',
            function ($contentTables) use ($app, $config) {

                $platform = $app['db']->getDatabasePlatform();
                $prefix = $app['schema.prefix'];
                $contentTypes = $app['config']->get('contenttypes');

                foreach (array_keys($contentTypes) as $contentType) {
                    $contentTables[$contentType] = $app->share(function () use ($platform, $prefix, $config) {
                        return new Storage\ContentTypeTable($platform, $prefix, $config);
                    });
                }

                return $contentTables;
            }
        );
    }

    /**
     * Register twig global
     *
     * @param Application $app
     */
    private function registerTwigGlobal(Application $app)
    {
        $app['twig'] = $app->extend(
            'twig',
            function (\Twig_Environment $twig) use ($app) {
                $twig->addGlobal('locales', $this->getCurrentLocaleStructure());

                return $twig;
            }
        );
    }

    /**
     * Helper to check for translatable fields in a contenttype
     *
     * @param Array $fields
     */
    private function getTranslatableFields($fields)
    {
        $translatable = [];
        foreach ($fields as $name => $field) {
            if (isset($field['is_translateable'])  && $field['is_translateable'] === true && $field['type'] === 'templateselect') {
                $translatable[] = 'templatefields';
            } elseif (isset($field['is_translateable']) && $field['is_translateable'] === true) {
                $translatable[] = $name;
            }
        }

        return $translatable;
    }

    /**
     * Helper to get a the current locale structure
     */
    public function getCurrentLocaleStructure()
    {
        $config = $this->getConfig();
        $locales = $config['locales'];

        foreach ($locales as $iso => &$locale) {
            $requestAttributes = $this->app['request']->attributes->get('_route_params');

            if ($config['translate_slugs'] === true && $locale['slug'] !== $requestAttributes['_locale'] && $this->app['request']->get('slug')) {
                $repo = $this->app['storage']->getRepository('pages');
                $qb = $repo->createQueryBuilder();
                $qb->select($locale['slug'] . '_slug')
                    ->where($requestAttributes['_locale'] . '_slug = ?')
                    ->setParameter(0, $this->app['request']->get('slug'))
                    ->setMaxResults(1);
                $result = $qb->execute()->fetch();
                $newSlug = array_values($result)[0];

                if (!empty($newSlug)) {
                    $requestAttributes['slug'] = $newSlug;
                }
            }

            $requestAttributes['_locale'] = $locale['slug'];

            $locale['url'] = $this->app['url_generator']->generate($this->app['request']->get('_route'), $requestAttributes);
            if ($this->localeSlug === $locale['slug']) {
                $locale['active'] = true;
            }
        }

        return $locales;
    }

    /**
     * Twig helper to render a locale switcher on the frontend
     *
     * @param String $classes
     * @param String $template
     */
    public function localeSwitcher(array $args = [])
    {
        $defaults = [
              'classes'  => '',
              'template' => '@bolt/frontend/_localeswitcher.twig',
        ];
        $args = array_merge($defaults, $args);

        $html = $this->app['twig']->render($args['template'], [
            'classes' => $args['classes'],
        ]);

        return new \Twig_Markup($html, 'UTF-8');
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        return [
            'locales' => [],
        ];
    }
}
