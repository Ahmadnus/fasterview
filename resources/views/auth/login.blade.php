@extends('layout')
@section('title', 'Log in')

@section('content')
    <div class="panel" style="max-width:380px;margin:60px auto">
        <h2 style="margin-top:0">Log in</h2>

        @if ($errors->any())
            <div class="flash err">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div style="margin-bottom:12px">
                <label class="small muted" for="email">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}"
                       required autofocus style="width:100%">
            </div>

            <div style="margin-bottom:12px">
                <label class="small muted" for="password">Password</label>
                <input type="password" name="password" id="password" required style="width:100%">
            </div>

            <label class="small muted" style="display:block;margin-bottom:14px">
                <input type="checkbox" name="remember" value="1"> Stay logged in
            </label>

            <button style="width:100%">Log in</button>
        </form>
    </div>
@endsection
