@extends('layouts.app')
@section('title', 'Page Not Found')
@section('content')
    <x-error-page code="404" title="We could not find that page" message="The address may be incorrect, or the requested record may no longer be available." icon="search" />
@endsection
