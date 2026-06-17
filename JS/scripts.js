// function showLocalDate(selector) {
// 	const now = new Date();
// 	const day = String(now.getDate()).padStart(2,'0');
// 	const monthNames = ["Jan.","Feb.","Mar.","Apr.","May","Jun.","Jul.","Aug.","Sep.","Oct.","Nov.","Dec."];
// 	const month = monthNames[now.getMonth()];
// 	const year = now.getFullYear();
// 	const hours = String(now.getHours()).padStart(2,'0');
// 	const minutes = String(now.getMinutes()).padStart(2,'0');
// 	const formatted = `${day} ${month} ${year}, ${hours}:${minutes}`;
// 	
// 	document.querySelectorAll(selector).forEach(el => {
// 		el.textContent = formatted;
// 	});
// }


// For Settings small box =============
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