<?php
declare(strict_types=1);

namespace RiTriever\Embedding;

use RiTriever\Settings;

final class CustomHttpEmbeddingProvider implements EmbeddingProviderInterface
{
    public function model(): string
    {
        $model = trim((string) Settings::get("custom_embedding_model"));
        return $model !== ""
            ? $model
            : "custom-http-" . (int) Settings::get("embedding_dimensions");
    }

    public function embed(string $text): array
    {
        $many = $this->embed_many([$text]);
        return $many[0] ?? [];
    }

    public function embed_many(array $texts): array
    {
        $url = (string) Settings::get("custom_embedding_endpoint");
        if ($url === "") {
            throw new \RuntimeException("Custom embedding endpoint is empty.");
        }
        $headers = ["Content-Type" => "application/json"];
        $key = (string) Settings::get("custom_embedding_api_key");
        $model = trim((string) Settings::get("custom_embedding_model"));
        $format = (string) Settings::get("custom_embedding_format");
        if ($key !== "") {
            if ($format === "azure_openai") {
                $headers["api-key"] = $key;
            } else {
                $headers["Authorization"] = "Bearer " . $key;
            }
        }
        $body = $this->request_body($format, $model, array_values($texts));
        $response = wp_remote_post($url, [
            "timeout" => 30,
            "headers" => $headers,
            "body" => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not output here; admin render escapes notices.
            throw new \RuntimeException($response->get_error_message());
        }
        $payload = json_decode(
            (string) wp_remote_retrieve_body($response),
            true,
        );
        if (isset($payload["embeddings"]) && is_array($payload["embeddings"])) {
            if (
                isset($payload["embeddings"]["float"]) &&
                is_array($payload["embeddings"]["float"])
            ) {
                return array_map(
                    static fn($v) => array_map("floatval", (array) $v),
                    $payload["embeddings"]["float"],
                );
            }
            return array_map(
                static fn($v) => array_map("floatval", (array) $v),
                $payload["embeddings"],
            );
        }
        if (isset($payload["embedding"]) && is_array($payload["embedding"])) {
            return [array_map("floatval", $payload["embedding"])];
        }
        if (isset($payload["data"]) && is_array($payload["data"])) {
            $out = [];
            foreach ($payload["data"] as $item) {
                if (isset($item["embedding"]) && is_array($item["embedding"])) {
                    $out[] = array_map("floatval", $item["embedding"]);
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        throw new \RuntimeException(
            "Custom embedding endpoint returned no embedding(s).",
        );
    }

    /** @param string[] $texts @return array<string,mixed> */
    private function request_body(
        string $format,
        string $model,
        array $texts,
    ): array {
        if ($format === "azure_openai") {
            $body = ["input" => $texts];
            $dimensions = (int) Settings::get("embedding_dimensions");
            if ($dimensions > 0) {
                $body["dimensions"] = $dimensions;
            }
            return $body;
        }
        return [
            "model" => $model,
            "input" => $texts,
        ];
    }
}
