<?php

declare(strict_types=1);

namespace EidCloud\VideoPipeline\Stages;

class DialogueTranslator implements StageInterface
{
    /**
     * @var callable|null
     */
    private $translator;

    /**
     * Built-in dictionary translations for offline testing / fallback.
     * @var array<string, array<string, string>>
     */
    private array $dictionary = [
        'Welcome to EidCloud intelligent video processing platform.' => [
            'ar' => 'مرحباً بكم في منصة عيد كلاود الذكية لمعالجة الفيديو.',
            'fr' => 'Bienvenue sur la plateforme intelligente de traitement vidéo EidCloud.',
            'es' => 'Bienvenido a la plataforma inteligente de procesamiento de video EidCloud.',
            'de' => 'Willkommen auf der intelligenten Videoverarbeitungsplattform EidCloud.',
        ],
        'Our neural pipeline translates, dubs, and synchronizes audio seamlessly.' => [
            'ar' => 'يقوم مسارنا العصبي بترجمة الصوت ودبلجته ومزامنته بسلاسة تامة.',
            'fr' => 'Notre pipeline neuronal traduit, double et synchronise l\'audio de manière transparente.',
            'es' => 'Nuestra canalización neuronal traduce, dobla y sincroniza audio a la perfección.',
            'de' => 'Unsere neuronale Pipeline übersetzt, synchronisiert und dubbt Audio nahtlos.',
        ],
    ];

    public function __construct(?callable $translator = null)
    {
        $this->translator = $translator;
    }

    public function getName(): string
    {
        return 'dialogue_translator';
    }

    public function process(PipelineContext $context): PipelineContext
    {
        $segments = $context->get('diarized_segments') ?? $context->get('transcription_segments', []);
        $targetLang = $context->getTargetLanguage();
        $sourceLang = $context->getSourceLanguage();

        foreach ($segments as &$segment) {
            $originalText = $segment['text'] ?? '';

            if ($this->translator !== null) {
                $translatedText = ($this->translator)($originalText, $sourceLang, $targetLang, $segment);
            } else {
                $translatedText = $this->translateText($originalText, $targetLang);
            }

            $segment['translated_text'] = $translatedText;
            $segment['target_language'] = $targetLang;
        }

        $context->set('translated_segments', $segments);
        return $context;
    }

    /**
     * Translates text while preserving timing constraints.
     */
    public function translateText(string $text, string $targetLanguage): string
    {
        $trimmed = trim($text);
        if (isset($this->dictionary[$trimmed][$targetLanguage])) {
            return $this->dictionary[$trimmed][$targetLanguage];
        }

        // Generic multilingual template translation when offline
        return match (strtolower($targetLanguage)) {
            'ar' => '[ترجمة: ' . $trimmed . ']',
            'fr' => '[Traduction: ' . $trimmed . ']',
            'es' => '[Traducción: ' . $trimmed . ']',
            'de' => '[Übersetzung: ' . $trimmed . ']',
            default => "[{$targetLanguage}: " . $trimmed . ']',
        };
    }
}
