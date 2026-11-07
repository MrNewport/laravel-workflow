{{-- The host authorizes the run and passes its historyMessages() result. --}}
<section aria-label="{{ __('laravel-workflow::messages.messages') }}">
    <ol class="space-y-4">
        @foreach ($messages as $message)
            <li>
                <span class="font-medium">{{ $message->author }}</span>
                <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->utc()->format('Y-m-d H:i') }} UTC</time>
                <p class="whitespace-pre-wrap break-words">{{ $message->body }}</p>
            </li>
        @endforeach
    </ol>
    {{ $messages->links() }}
</section>
