<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SignalController;
use App\Http\Controllers\SlideController;
use App\Http\Controllers\WebinarEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'home'])->name('home');

// Optional user accounts and recordings archive (last 3 live webinars).
Route::get('/login', [AccountController::class, 'showLogin'])->name('login');
Route::post('/login', [AccountController::class, 'login'])->name('login.store');
Route::get('/register', [AccountController::class, 'showRegister'])->name('register');
Route::post('/register', [AccountController::class, 'register'])->name('register.store');
Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
Route::get('/events', [EventController::class, 'index'])->name('events.index');
Route::post('/events/{slug}', [EventController::class, 'update'])->name('events.update');
Route::get('/recordings', [AccountController::class, 'recordings'])->name('recordings.index');
Route::get('/recordings/{recording}/video', [LiveController::class, 'downloadRecording'])->whereNumber('recording')->name('recordings.video');

// Presenter creates a room; the presenter key lives in the studio URL.
Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');

// Public webinar registration, cover artwork & token-gated replay access.
Route::get('/r/{slug}/cover', [EventController::class, 'cover'])->name('webinar.cover');
Route::get('/r/{slug}/register', [RegistrationController::class, 'show'])->name('webinar.register');
Route::post('/r/{slug}/register', [RegistrationController::class, 'store'])->name('webinar.register.store');
Route::get('/r/{slug}/replay/{token}', [RegistrationController::class, 'replay'])->name('replay.show');
Route::get('/r/{slug}/replay/{token}/video', [RegistrationController::class, 'replayVideo'])->name('replay.video');

// Guest side: join with a display name, then watch the stage and chat.
Route::get('/r/{slug}', [RoomController::class, 'show'])->name('room');
Route::post('/r/{slug}/join', [RoomController::class, 'join'])->name('room.join');
Route::get('/r/{slug}/live', [SlideController::class, 'live'])->name('room.live');
Route::get('/r/{slug}/slides/{slide}', [SlideController::class, 'download'])->whereNumber('slide')->name('room.slide');
Route::get('/r/{slug}/slides/{slide}/pages', [SlideController::class, 'slidesData'])->whereNumber('slide')->name('room.slide.pages');
Route::get('/r/{slug}/chat/{channel}', [ChatController::class, 'index'])->name('room.chat');
Route::post('/r/{slug}/chat/{channel}', [ChatController::class, 'store'])->name('room.chat.store');
Route::post('/r/{slug}/moderate/{message}/{action}', [ChatController::class, 'moderate'])->whereNumber('message')->name('room.moderate');
Route::get('/r/{slug}/signals', [SignalController::class, 'index'])->name('room.signals');
Route::post('/r/{slug}/signals', [SignalController::class, 'store'])->name('room.signals.store');

// Presenter studio: keyed URL, backstage chat, slide deck upload, live slide + broadcast controls.
Route::get('/studio/{slug}/{key}', [RoomController::class, 'studio'])->name('studio');
Route::post('/studio/{slug}/{key}/slides', [SlideController::class, 'store'])->name('studio.slides');
Route::post('/studio/{slug}/{key}/live', [RoomController::class, 'setCurrentSlide'])->name('studio.live');
Route::post('/studio/{slug}/{key}/go-live', [LiveController::class, 'goLive'])->name('studio.go-live');
Route::post('/studio/{slug}/{key}/end-live', [LiveController::class, 'endLive'])->name('studio.end-live');
Route::post('/studio/{slug}/{key}/layout', [LiveController::class, 'updateLayout'])->name('studio.layout');
Route::post('/studio/{slug}/{key}/recordings', [LiveController::class, 'storeRecording'])->name('studio.recordings');
Route::get('/studio/{slug}/{key}/emails', [WebinarEmailController::class, 'index'])->name('studio.emails');
Route::post('/studio/{slug}/{key}/schedule', [WebinarEmailController::class, 'updateSchedule'])->name('studio.schedule');
Route::post('/studio/{slug}/{key}/emails/templates/{templateKey}', [WebinarEmailController::class, 'updateTemplate'])->name('studio.emails.template.update');
Route::post('/studio/{slug}/{key}/emails/templates/{templateKey}/reset', [WebinarEmailController::class, 'resetTemplate'])->name('studio.emails.template.reset');
Route::get('/studio/{slug}/{key}/emails/templates/{templateKey}/preview', [WebinarEmailController::class, 'previewTemplate'])->name('studio.emails.template.preview');
Route::post('/studio/{slug}/{key}/emails/language', [WebinarEmailController::class, 'setLanguage'])->name('studio.emails.language');
Route::post('/studio/{slug}/{key}/emails/dispatch', [WebinarEmailController::class, 'dispatchNow'])->name('studio.emails.dispatch');
