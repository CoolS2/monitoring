final class TelegramConfig
{
    public function __construct(
        public readonly string $token,
        public readonly string $chatId,
    ) {}
}
