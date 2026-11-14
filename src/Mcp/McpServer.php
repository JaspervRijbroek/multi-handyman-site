<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * A minimal MCP server (Model Context Protocol, JSON-RPC 2.0) that only offers tools.
 * Stateless: every request is answered on its own, no sessions or streaming.
 *
 * See https://modelcontextprotocol.io/specification
 */
final readonly class McpServer
{
    private const array PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private McpTools $tools,
    ) {
    }

    /**
     * Handles one JSON-RPC message and returns the response, or null for a
     * notification (which gets no response).
     *
     * @param array<mixed> $message
     *
     * @return array<string, mixed>|null
     */
    public function handle(array $message): ?array
    {
        $method = $message['method'] ?? null;

        // Notifications ("notifications/initialized" ...) and responses from the client need no answer
        if (!\array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        if (!\is_string($method)) {
            return self::error($id, -32600, 'Invalid request.');
        }

        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => $this->tools->definitions()]),
            'tools/call' => $this->callTool($id, $params),
            default => self::error($id, -32601, \sprintf('Method "%s" not found.', $method)),
        };
    }

    /** @param array<mixed> $params */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => \in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'casitafix', 'title' => 'CasitaFix website', 'version' => '1.0.0'],
            'instructions' => 'Manage one business website: business details and texts, services, finished jobs, '
                .'enquiries from the contact form and customer reviews. Texts are maps of language code to text, '
                .'e.g. {"en": "Painting", "es": "Pintura"}.',
        ];
    }

    /** @param array<mixed> $params */
    private function callTool(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || !$this->tools->has($name)) {
            return self::error($id, -32602, \sprintf('Unknown tool "%s".', \is_string($name) ? $name : ''));
        }

        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = $this->tools->call($name, $arguments);
            $isError = false;
        } catch (McpToolException $e) {
            // Errors the assistant can fix (validation, unknown ID) are reported as a tool result
            $data = ['error' => $e->getMessage()];
            $isError = true;
        }

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)]],
            'isError' => $isError,
        ]);
    }

    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
