<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomClientPortalRedirect
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get the currently authenticated contact
        $contact = Auth::guard('contact')->user();

        if (!$contact) {
            return $next($request);
        }

        // Get the company for this contact
        $company = Company::find($contact->company_id);

        if (!$company) {
            return $next($request);
        }

        // Get the company's configured portal domain using the domain() method
        $portalDomain = $company->portal_domain;

        if (empty($portalDomain)) {
            return $next($request);
        }

        // Parse the portal domain URL to extract the host
        $parsedPortalUrl = parse_url($portalDomain);

        if (!$parsedPortalUrl || !isset($parsedPortalUrl['host'])) {
            return $next($request);
        }

        $portalHost = $parsedPortalUrl['host'];
        $currentHost = $request->getHost();

        // If current domain doesn't match the portal domain, redirect
        if ($currentHost !== $portalHost) {
            // Get the request URI and strip the "client" subdirectory if it's at the beginning
            $requestUri = $request->getRequestUri();
            $redirectUri = preg_replace('/^\/client/', '', $requestUri, 1);

            // Build the redirect URL with the modified URI
            $redirectUrl = $portalDomain . $redirectUri;

            return redirect($redirectUrl, 301);
        }

        return $next($request);
    }
}
