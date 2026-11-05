<div class="subnav">
    <a href="{{ route('coop.guide') }}" class="{{ request()->routeIs('coop.guide') ? 'active' : '' }}">How to test</a>
    <a href="{{ route('coop.accounts') }}" class="{{ request()->routeIs('coop.accounts*') ? 'active' : '' }}">Accounts</a>
    <a href="{{ route('coop.send') }}" class="{{ request()->routeIs('coop.send*') ? 'active' : '' }}">Send money (transfers)</a>
    <a href="{{ route('coop.transfers') }}" class="{{ request()->routeIs('coop.transfers*') ? 'active' : '' }}">Transfers &amp; callbacks</a>
    <a href="{{ route('coop.collections') }}" class="{{ request()->routeIs('coop.collections*') ? 'active' : '' }}">Collections &amp; IPN</a>
</div>
