<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\VerifyEmailNotice;
use App\Livewire\Auth\VerifyPhone;
use App\Livewire\BrowseListings;
use App\Livewire\Favorites\MyFavorites;
use App\Livewire\Home;
use App\Livewire\SavedSearches\MySearches;
use App\Livewire\ShowPart;
use App\Livewire\Listings\CreateListing;
use App\Livewire\Listings\EditListing;
use App\Livewire\Listings\MyListings;
use App\Livewire\Deals\MyDeals;
use App\Livewire\Messages\Inbox;
use App\Livewire\Moderation\Queue as ModerationQueue;
use App\Livewire\Messages\ShowThread;
use App\Livewire\Offers\OfferInbox;
use App\Livewire\Profile\EditProfile;
use App\Livewire\Profile\ShowProfile;
use App\Livewire\ShowListing;
use App\Http\Controllers\Sitemap;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Public URLs are Bulgarian, because they are read by Bulgarian users and by
 * Google in Bulgarian. Listings are addressed by uuid rather than id so the
 * site never advertises how few (or how many) listings it really has.
 */

// --- public ---------------------------------------------------------------
// Browsing stays open. Gating reading behind signup is how a marketplace with
// no users stays a marketplace with no users.
Route::get('/', Home::class)->name('home');

/*
 * Browse moved off "/" when the home page arrived. The route NAME is unchanged,
 * so every link, redirect and test that pointed at route('browse') still lands
 * on the listing grid - only the URL is new.
 */
Route::get('/obiavi', BrowseListings::class)->name('browse');
Route::get('/obiava/{listing}', ShowListing::class)->name('listing');
Route::get('/profil/{username}', ShowProfile::class)->name('profile');

/*
 * The catalogue landing pages - the entire organic-search strategy.
 *
 * Nobody searches for this site by name. They search for "rtx 4070 цена бг",
 * and a listing cannot answer that: it is one asking price and it disappears
 * when the card sells, taking its rankings with it. These pages are permanent,
 * accumulate links, and keep working when there is nothing for sale.
 *
 * `/model/` rather than `/chast/`: it is what the page is about, it is short,
 * and it reads the same in both alphabets.
 */
Route::get('/model/{part}', ShowPart::class)->name('part');

/*
 * Landing pages nobody crawls are worth nothing, so the sitemap ships with
 * them rather than "later". It refuses to serve anything when the deployment
 * is not indexable - see config('remarket.seo.indexable').
 */
Route::get('/sitemap.xml', Sitemap::class)->name('sitemap');

/*
 * The legal pages. Public and unauthenticated on purpose: DSA Art. 11 and 12
 * contact points behind a login would not be published at all, and someone
 * whose photographs were stolen has to be able to read how to report it
 * without first creating an account here.
 *
 * Static views rather than components - no state, no interaction, and they
 * should keep rendering if every other part of the site is broken.
 */
Route::view('/usloviya', 'legal.terms')->name('legal.terms');
Route::view('/poveritelnost', 'legal.privacy')->name('legal.privacy');
Route::view('/biskvitki', 'legal.cookies')->name('legal.cookies');
Route::view('/kontakti', 'legal.contacts')->name('legal.contacts');
Route::view('/signali', 'legal.notice')->name('legal.notice');

// --- guests ---------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/registraciya', Register::class)->name('register');
    Route::get('/vhod', Login::class)->name('login');
});

// --- authenticated --------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/potvardi-telefon', VerifyPhone::class)->name('phone.verify');

    /*
     * Email verification. These three route NAMES are not optional once User
     * implements MustVerifyEmail: Laravel's built-in VerifyEmail notification
     * builds its signed link from route('verification.verify'), so registering
     * a user without them throws RouteNotFoundException mid-signup.
     */
    Route::get('/potvardi-imeil', VerifyEmailNotice::class)
        ->name('verification.notice');

    Route::get('/potvardi-imeil/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('browse')->with('status', 'Имейлът е потвърден.');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::post('/potvardi-imeil/prati', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Изпратихме нов линк за потвърждение.');
    })->middleware('throttle:6,1')->name('verification.send');
    Route::get('/nastroyki', EditProfile::class)->name('profile.edit');

    /*
     * The shortlist and the standing wants. Neither needs a verified email:
     * they are reading and remembering, not acting on anyone else.
     */
    Route::get('/zapazeni', MyFavorites::class)->name('favorites');
    Route::get('/zapazeni-tarseniya', MySearches::class)->name('searches');

    Route::get('/oferti', OfferInbox::class)->name('offers');
    Route::get('/sdelki', MyDeals::class)->name('deals');

    Route::get('/sabshteniya', Inbox::class)->name('messages');
    Route::get('/sabshteniya/{thread}', ShowThread::class)->name('thread');
    // Entry point from a listing: opens the thread if it does not exist yet,
    // then redirects to its own URL.
    Route::get('/obiava/{listing}/pisha', ShowThread::class)
        ->middleware('verified')
        ->name('listing.message');

    /*
     * Creating and changing content needs a verified email; reading your own
     * does not. Someone who cannot yet act should still be able to see the
     * state of their own account.
     *
     * 'phone.verified' is still NOT applied. It is the stronger gate and it
     * goes on before launch - the Telegram bot makes it free, so the only
     * reason it is off is that the site is still being built.
     */
    Route::get('/publikuvai', CreateListing::class)
        ->middleware('verified')
        ->name('listing.create');

    /*
     * The seller's own listings, and editing one.
     *
     * Both are owner-only - EditListing 404s on someone else's listing, and
     * every mutation goes through ListingService, which checks ownership again.
     * Two checks rather than one because the route is the thing most likely to
     * be refactored later.
     */
    Route::get('/moite-obiavi', MyListings::class)->name('listings.mine');
    Route::get('/obiava/{listing}/redakciya', EditListing::class)
        ->middleware('verified')
        ->name('listing.edit');

    /*
     * Moderation. The middleware answers 404 rather than 403 to anyone who is
     * not a moderator: a 403 confirms the URL is real and worth attacking.
     */
    Route::get('/moderaciya', ModerationQueue::class)
        ->middleware('admin')
        ->name('moderation');

    Route::post('/izhod', function () {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('browse');
    })->name('logout');
});
