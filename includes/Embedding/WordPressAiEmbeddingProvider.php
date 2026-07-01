<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

use RiTriever\Settings;

final class WordPressAiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function model(): string
    {
        return (string) Settings::get("wp_ai_embedding_model");
    }

    public function embed(string $text): array
    {
        $many = $this->embed_many([$text]);
        return $many[0] ?? [];
    }

    public function embed_many(array $texts): array
    {
        if (!function_exists("wp_ai_client_prompt")) {
            throw new \RuntimeException(
                "WordPress AI Client embedding API is unavailable. Use WordPress 7.0 AI settings or choose OpenAI/Azure OpenAI fallback.",
            );
        }

        $builder = \wp_ai_client_prompt(array_values($texts));
        $model = trim($this->model());
        if ($model !== "") {
            $builder = $this->call_builder($builder, "using_model_preference", [
                $model,
            ]);
        }

        $supported = $this->try_support_check($builder);
        if ($supported === false) {
            throw new \RuntimeException(
                "The configured WordPress AI provider does not expose embedding generation for " .
                    esc_html($model) .
                    ". Choose OpenAI/Azure OpenAI fallback or a WordPress AI provider model with embedding support.",
            );
        }

        $result = $this->call_builder($builder, "generate_embeddings", false);
        if ($result === $builder) {
            $result = $this->generate_embedding_result($builder);
        }
        if (is_wp_error($result)) {
            throw new \RuntimeException(esc_html($result->get_error_message()));
        }

        $embeddings = $this->normalize_embeddings($result);
        if ($embeddings === []) {
            throw new \RuntimeException(
                "WordPress AI Client returned no embedding vectors.",
            );
        }
        return $embeddings;
    }

    /** @param mixed $builder @param mixed[] $args @return mixed */
    private function call_builder(
        $builder,
        string $method,
        array $args,
        bool $must_chain = true,
    ) {
        try {
            $result = $builder->{$method}(...$args);
            if (!$must_chain && $result === $builder) {
                return $builder;
            }
            return $result;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "WordPress AI Client method " .
                    esc_html($method) .
                    " failed: " .
                    esc_html($e->getMessage()),
            );
        }
    }

    /** @param mixed $builder @return mixed */
    private function generate_embedding_result($builder)
    {
        $capability = null;
        if (
            class_exists("\\WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum") &&
            is_callable([
                "\\WordPress\\AiClient\\Providers\\Models\\Enums\\CapabilityEnum",
                "embeddingGeneration",
            ])
        ) {
            $capability = \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::embeddingGeneration();
        }
        try {
            return $builder->generate_result($capability);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "WordPress AI Client embedding generation failed: " .
                    esc_html($e->getMessage()),
            );
        }
    }

    /** @param mixed $builder */
    private function try_support_check($builder): ?bool
    {
        try {
            $supported = $builder->is_supported_for_embedding_generation();
            return is_bool($supported) ? $supported : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param mixed $result @return array<int,array<int,float>> */
    private function normalize_embeddings($result): array
    {
        if (is_object($result) && is_callable([$result, "toArray"])) {
            $result = $result->toArray();
        }
        if (!is_array($result)) {
            return [];
        }
        if ($this->is_vector($result)) {
            return [array_map("floatval", $result)];
        }
        if ($this->is_vector_list($result)) {
            return array_map(
                static fn(array $vector): array => array_map("floatval", $vector),
                $result,
            );
        }
        foreach (["embeddings", "embedding", "data", "additionalData"] as $key) {
            if (isset($result[$key])) {
                $normalized = $this->normalize_embeddings($result[$key]);
                if ($normalized !== []) {
                    return $normalized;
                }
            }
        }
        foreach ($result as $item) {
            if (is_array($item)) {
                $normalized = $this->normalize_embeddings($item);
                if ($normalized !== []) {
                    return $normalized;
                }
            }
        }
        return [];
    }

    private function is_vector(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_int($item) && !is_float($item)) {
                return false;
            }
        }
        return true;
    }

    private function is_vector_list(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_array($item) || !$this->is_vector($item)) {
                return false;
            }
        }
        return true;
    }
}
