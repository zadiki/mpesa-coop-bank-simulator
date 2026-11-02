<div class="subnav">
    <a href="{{ route('coop.accounts') }}" class="{{ request()->routeIs('coop.accounts*') ? 'active' : '' }}">Accounts</a>
    <a href="{{ route('coop.transfers') }}" class="{{ request()->routeIs('coop.transfers*') ? 'active' : '' }}">Transfers</a>
    <a href="{{ route('coop.send') }}" class="{{ request()->routeIs('coop.send*') ? 'active' : '' }}">Send money</a>
</div>
