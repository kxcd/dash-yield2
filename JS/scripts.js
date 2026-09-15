// Set the Dark/Light mode theme.
(function () {
	const key = 'dash-yield-theme';
	const saved = localStorage.getItem(key);
	const theme = saved || (window.matchMedia &&
		window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
	// alert(theme);
	document.documentElement.dataset.theme = theme;
})();

// Settings small box =============
function changefiat() {
	var fiat = document.getElementById("fiatselect").value;
	var expires = new Date();
	expires.setFullYear(expires.getFullYear() + 1);
	document.cookie = "fiat=" + fiat + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
	window.location.href = window.location.pathname + "?fiat=" + fiat;
}
function changelang() {
	var lang = document.getElementById("langselect").value;
	var expires = new Date();
	expires.setFullYear(expires.getFullYear() + 1);
	document.cookie = "lang=" + lang + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
	window.location.href = window.location.pathname + "?lang=" + lang;
}
function changetimescale() {
	var timescale = document.getElementById("timescaleselect").value;
	var expires = new Date();
	expires.setFullYear(expires.getFullYear() + 1);
	document.cookie = "timescale=" + timescale + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
	window.location.href = window.location.pathname + "?timescale=" + timescale;
}

function sharePage() {
	const cleanUrl = `${location.origin}${location.pathname}`;
	if (navigator.share) {
		navigator.share({
			title: document.title,
			url: cleanUrl
		});
	} else { // Fallback : copy-paste
		navigator.clipboard.writeText(cleanUrl).then(() => {
			alert("Link copied to clipboard ! 😀");
		});
	}
}


// Partial collateral inputs =============
const refusalCount = {};
function partial(fieldId, timescale = "yearly") {
	const input = document.getElementById('coll-' + fieldId);
	const rawValue = input.value;
	const value = Number(rawValue);
	const min = Number(input.min); // 1
	const fullcollateral = Number(input.placeholder); // 1000 or 4000
	
	if (refusalCount[fieldId] === undefined)
	refusalCount[fieldId] = 0;
	
	const isValid = rawValue !== '' && Number.isInteger(value) && value >= min;
	if (!isValid) {
		refusalCount[fieldId]++;
		const lastValid = input.dataset.lastValid ?? input.placeholder;
		input.value = lastValid;
		if (refusalCount[fieldId] >= 5)
			alert(JSalert);
		
	} else { // valid partial collateral entered by the visitor
		refusalCount[fieldId] = 0;
		input.dataset.lastValid = rawValue;
		const thenumber = document.getElementById(fieldId + "-number");
		const howmuchdash = document.getElementById(fieldId + "-earning");
		const howmuchfiat = document.getElementById(fieldId + "-fiat-earning");
		// updated number of MNs/eMNs
		var newnumber =  Number((value / fullcollateral).toFixed(1));
		var times = "";
		if (newnumber != 1)
			times = " × ";
		if (newnumber < 0.1)
			newnumber = "≈ 0.05";
		else if (!Number.isInteger(newnumber))
			newnumber = "≈ " + newnumber;
		thenumber.textContent = newnumber + times;
		howmuchdash.innerHTML = thousandsanddecimals(((value / fullcollateral) * howmuchdash.dataset.placeholder).toFixed(1));
		howmuchfiat.innerHTML = thousandsanddecimals(((value / fullcollateral) * howmuchfiat.dataset.placeholder).toFixed(0));
		// funky bounces to show it
		[thenumber, howmuchdash, howmuchfiat].forEach(element => {
			element.classList.remove('earning-update');
			void element.offsetWidth;
			element.classList.add('earning-update');
			element.addEventListener('animationend', () => {
				element.classList.remove('earning-update');
			}, { once: true });
		});

	}
}

// 1000 or 4000 increments management (using up/down arrow keys)
document.addEventListener("DOMContentLoaded", () => {
	['coll-MN', 'coll-Evo'].forEach(id => {
		const timescale = getCookie("timescale", "yearly");
		const input = document.getElementById(id);
		if (!input)
			return;
		const stepValue = Number(input.placeholder); // 1000 for MN, 4000 for Evo
		let lastValue = Number(input.value);
		// 1 : up/down arrow on keyboard
		input.addEventListener('keydown', (e) => {
			let currentValue = Number(input.value) || 0;
			if (e.key === 'ArrowUp') {
				e.preventDefault();
				let newValue = Math.ceil((currentValue + 0.1) / stepValue) * stepValue;
				input.value = newValue;
				input.dispatchEvent(new Event('input'));
			} else if (e.key === 'ArrowDown') {
				e.preventDefault();
				let newValue = Math.floor((currentValue - 0.1) / stepValue) * stepValue;
				input.value = Math.max(Number(input.min) || 1, newValue);
				
				input.dispatchEvent(new Event('input'));
			}
		});
		// 2 : browser arrows clicked
		input.addEventListener('input', (e) => {
			if (e.inputType !== 'insertText' && e.inputType !== 'deleteContentBackward') {
				let currentValue = Number(input.value) || 0;
				if (currentValue > lastValue) {
					let newValue = Math.ceil((lastValue + 0.1) / stepValue) * stepValue;
					input.value = newValue;
				} 
				else if (currentValue < lastValue) {
					let newValue = Math.floor((lastValue - 0.1) / stepValue) * stepValue;
					input.value = Math.max(Number(input.min) || 1, newValue);
				}
				partial(id.replace('coll-', ''), timescale); 
			}
			lastValue = Number(input.value);
		});
	});
});


// Example of a 125D collateral
function sharedMN(timescale) {
	document.getElementById("coll-MN").value = "125";
	partial("MN", timescale);
}

function thousandsanddecimals(number) {
	return number.toString().replace(
		/^(-?\d+)([.,]\d+)?$/,
		(_, integer, decimals = '') =>
			integer.replace(/\B(?=(\d{3})+(?!\d))/g, '&nbsp;') +
			(decimals ? `<span class=\"decimals\">${decimals}</span>` : '')
	);
}

// Time zone detection =============
const tz = Intl.DateTimeFormat().resolvedOptions().timeZone; // ex: "UTC", "Europe/Paris"
document.cookie = `user_tz=${tz}; path=/; SameSite=Lax`;


// Cookie detection ============
function getCookie(name, defaultValue = null) {
	const cookie = document.cookie
		.split("; ")
		.find(row => row.startsWith(name + "="));
	return cookie
		? decodeURIComponent(cookie.substring(name.length + 1))
		: defaultValue;
}

function maybePlayAnimation() {
	'use strict';

	const VISIT_KEY = 'dash-yield-last-visit';
	const FORTY_EIGHT_HOURS = 48 * 60 * 60 * 1000;

	// Show the intro animation only once each other day
	const now = Date.now();


	const lastAnimation = Number(localStorage.getItem(VISIT_KEY) || 0);
	const shouldShow = !lastAnimation || (now - lastAnimation > FORTY_EIGHT_HOURS);
	if (shouldShow) {
		localStorage.setItem(VISIT_KEY, String(now));
	}


	const animation = document.getElementById('visitAnimation');
	if (shouldShow && animation) {
		animation.hidden = false;

		/* Keep the SVG visible long enough to qualify as a brief intro,
		   then let CSS perform the opacity fade. */
		animation.addEventListener('animationend', function () {
			animation.hidden = true;
		}, { once: true });
	}
}

document.addEventListener('DOMContentLoaded', () => {
	maybePlayAnimation();
});
