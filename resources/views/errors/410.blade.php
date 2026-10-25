@extends('errors._ecosystem-layout', ['code' => 410])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#e3ab1f" stroke-width="1.75"><circle cx="12" cy="13" r="8" stroke-linecap="round"/><path d="M12 9v4l2.5 2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 2h6" stroke-linecap="round"/></svg>
@endsection

@section('title', 'This link has expired')
@section('message', 'The person who shared this with you set it to stop working after a while. Ask them for a new link.')

@section('actions')
    <a href="/" class="press" style="padding: 12px 24px; background: var(--accent); color: #12231f; font-family: var(--font-display); font-weight: 600; font-size: 15px; border-radius: 999px; text-decoration: none;">Go home</a>
@endsection
