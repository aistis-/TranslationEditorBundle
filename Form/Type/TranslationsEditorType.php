<?php

namespace Nercury\TranslationEditorBundle\Form\Type;

use Nercury\TranslationEditorBundle\Form\Listener\TranslationDataSortListener;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\EventListener\ResizeFormListener;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Form type used for editing translation collection for specified locales.
 */
class TranslationsEditorType extends AbstractType
{
    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * Set translator.
     *
     * @param TranslatorInterface $translator
     */
    public function setTranslator(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @var \Symfony\Bridge\Doctrine\RegistryInterface
     */
    protected $doctrine;

    public function setDoctrine($doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected $container;

    public function setContainer($container)
    {
        $this->container = $container;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Request
     */
    protected function getRequest()
    {
        return $this->container->hasScope('request') ? $this->container->get('request') : null;
    }

    /**
     * @return string
     */
    protected function getRequestLocale()
    {
        $request = $this->getRequest();

        return null === $request ? $this->container->getParameter('locale') : $request->getLocale();
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (empty($options['locales'])) {
            throw new \LogicException('The translation editor requires at least a single locale specified.');
        }

        $prototype = $builder->create(
            $options['prototype_name'],
            $options['type'],
            $options['options']
        );

        // Translation data sort listener is used to sort and add missing entities in the list based on the locales.

        $translationDataSorter = new TranslationDataSortListener(
            $options['locale_field_name'],
            $prototype->getDataClass(),
            $options['locales'],
            $options['null_locale_enabled'],
            $this->doctrine->getManager(),
            $options['auto_remove_ignore_fields']
        );

        $builder->addEventSubscriber($translationDataSorter);

        // Resize form listener is used to create forms for each collection entity.

        $resizeListener = new ResizeFormListener(
            $options['type'],
            $options['options'],
            false,
            false
        );

        $builder->addEventSubscriber($resizeListener);
    }

    /**
     * {@inheritdoc}
     */
    public function finishView(FormView $view, FormInterface $form, array $options)
    {
        if ($form->getConfig()->hasAttribute('prototype') && $view->vars['prototype']->vars['multipart']) {
            $view->vars['multipart'] = true;
        }

        $options = $form->getConfig()->getOptions();

        $view->vars['null_locale_enabled'] = $options['null_locale_enabled'];
        $view->vars['null_locale_selected'] = $options['null_locale_selected'];

        $requestLocale = $this->getRequestLocale();

        $view->vars['locale_titles'] = array();
        foreach ($options['locales'] as $locale) {
            $view->vars['locale_titles'][$locale] = \Locale::getDisplayName($locale, $requestLocale);
        }

        $request = $this->getRequest();

        if (null === $request) {
            $current_selected_lang = $options['locales'][0];
        } else {
            $current_selected_lang = $request->cookies->get(
                'current_selected_translation_lang',
                null
            );
            $selectedLanguageIsNotValid = $current_selected_lang !== '__all__' && !in_array(
                    $current_selected_lang,
                    $options['locales']
                );
            if ($current_selected_lang === null || $selectedLanguageIsNotValid) {
                $current_selected_lang = $options['locales'][0];
            }
        }

        $view->vars['current_selected_lang'] = $current_selected_lang;

        $view->vars['form_all_errors'] = array();
        if ((true === $options['all_errors']) && (1 < count($options['locales']))) {
            $view->vars['form_all_errors'] = $this->getErrorMessages($form, $view);
        }
    }

    /**
     * Get error messages.
     *
     * @param FormInterface $form
     * @param FormView $view
     *
     * @return array
     */
    private function getErrorMessages(FormInterface $form, FormView $view)
    {
        $errors = array();

        foreach ($form->getErrors() as $key => $error) {
            $errors[] = $error->getMessage();
        }

        $viewChildren = array();
        if (null !== $view) {
            $viewChildren = $view->children;
        }

        /** @var FormInterface $child */
        foreach ($form->all() as $key => $child) {
            if (!$child->isValid() && $child->isSubmitted()) {
                $label = null;
                $viewChild = (true === isset($viewChildren[$key])) ? $viewChildren[$key] : null;

                if ((null !== $viewChild) && $viewChildren[$key]->vars['label']) {
                    $label = $viewChildren[$key]->vars['label'];
                    if ($translationDomain = $viewChildren[$key]->vars['translation_domain']) {
                        $label = $this->translator->trans($label, array(), $translationDomain);
                    }
                }

                $errors[$child->getName()] = array(
                    'label' => $label,
                    'errors' => $this->getErrorMessages($child, $viewChild),
                );
            }
        }

        return $errors;
    }

    protected function getDefaultEditorLocales()
    {
        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $optionsNormalizer = function (Options $options, $value) {
            $value['block_name'] = 'entry';

            return $value;
        };

        $resolver->setDefaults(
            array(
                'allow_add' => false,
                'allow_delete' => false,
                'prototype' => false,
                'prototype_name' => '__protname__',
                'type' => 'text',
                'options' => array(),
                // List of all locales to manage.
                'locales' => $this->getDefaultEditorLocales(), // should pass yourself
                // A locale field name in related entity. If it is private, getLang and setLang are used.
                // This option uses property path notation. Also works with arrays (use "[lang]").
                'locale_field_name' => 'lang',
                // Allow editing "null" language.
                'null_locale_enabled' => false,
                // Editor has "null" language selected.
                'null_locale_selected' => false,
                // Automatically remove translation entity from db if it is empty.
                'auto_remove_empty_translations' => true,
                'auto_remove_ignore_fields' => array('created_at', 'updated_at'),
                'error_bubbling' => false,
                'all_errors' => false,
            )
        );

        $resolver->setNormalizers(
            array(
                'options' => $optionsNormalizer,
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'translations';
    }
}
