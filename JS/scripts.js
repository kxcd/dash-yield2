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
function partial(fieldId) {
	const input = document.getElementById('coll-' + fieldId);
	const rawValue = input.value;
	const value = Number(rawValue);
	const min = Number(input.min); // 1
	const max = Number(input.max); // 1000 or 4000
	
	if (refusalCount[fieldId] === undefined)
	refusalCount[fieldId] = 0;
	
	const isValid = rawValue !== '' && Number.isInteger(value) && value >= min && value <= max;
	if (!isValid) {
		refusalCount[fieldId]++;
		const lastValid = input.dataset.lastValid ?? input.placeholder;
		input.value = lastValid;
		if (refusalCount[fieldId] >= 5)
			alert(JSalert + min + " & " + max + ".");
		
	} else { // valid partial collateral entered by the visitor
		refusalCount[fieldId] = 0;
		input.dataset.lastValid = rawValue;
		const yearlydash = document.getElementById(fieldId + "-earning");
		const yearlyfiat = document.getElementById(fieldId + "-fiat-earning");
		// updated earnings
		yearlydash.textContent = ((value / max) * yearlydash.dataset.placeholder).toFixed(1);
		yearlyfiat.textContent = ((value / max) * yearlyfiat.dataset.placeholder).toFixed(1);
		// bounces to show it
		yearlydash.classList.remove('earning-update');
		yearlyfiat.classList.remove('earning-update');
		void yearlydash.offsetWidth;
		void yearlyfiat.offsetWidth;
		yearlydash.classList.add('earning-update');
		yearlyfiat.classList.add('earning-update');
		yearlydash.addEventListener('animationend', () => {
			yearlydash.classList.remove('earning-update');
		}, { once: true });
		yearlyfiat.addEventListener('animationend', () => {
			yearlyfiat.classList.remove('earning-update');
		}, { once: true });
	}
}