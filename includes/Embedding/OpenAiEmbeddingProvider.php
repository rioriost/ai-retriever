<?php
declare(strict_types=1);

namespace WPRetliever\Embedding;

use WPRetliever\Settings;

final class OpenAiEmbeddingProvider implements EmbeddingProviderInterface
{
    public function model(): string
    {
        return (string) Settings::get("openai_embedding_model");
    }

    public function embed(string $text): array
    {
        $many = $this->embed_many([$text]);
        return $many[0] ?? [];
    }

    public function embed_many(array $texts): array
    {
        $key = (string) Settings::get("openai_api_key");
        if ($key === "") {
            throw new \RuntimeException("OpenAI API key is empty.");
        }
        $body = [
            "model" => $this->model(),
            "input" => array_values($texts),
        ];
        $dimensions = (int) Settings::get("embedding_dimensions");
        if ($dimensions > 0) {
            $body["dimensions"] = $dimensions;
        }

        $response = wp_remote_post("https://api.openai.com/v1/embeddings", [
            "timeout" => 30,
            "headers" => [
                "Authorization" => "Bearer " . $key,
                "Content-Type" => "application/json",
            ],
            "body" => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not output here; admin render escapes notices.
            throw new \RuntimeException($response->get_error_message());
        }
        $body = (string) wp_remote_retrieve_body($response);
        $payload = json_decode($body, true);
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $message = is_array($payload["error"] ?? null)
                ? (string) ($payload["error"]["message"] ?? "")
                : "";
            if ($message === "") {
                $message = "Unexpected OpenAI API response.";
            }
            throw new \RuntimeException(
                "OpenAI API error " .
                    esc_html((string) $code) .
                    ": " .
                    esc_html($message),
            );
        }
        $data = is_array($payload["data"] ?? null) ? $payload["data"] : [];
        if ($data === []) {
            throw new \RuntimeException(
                "OpenAI embedding response returned no data.",
            );
        }
        $out = [];
        foreach ($data as $item) {
            $out[] = array_map(
                "floatval",
                is_array($item["embedding"] ?? null) ? $item["embedding"] : [],
            );
        }
        return $out;
    }
}
