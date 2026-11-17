// Tiny JSON helper for e2e.sh: echo '{...}' | node j.js 'd.data.id'
let t = '';
process.stdin.on('data', (c) => (t += c)).on('end', () => {
	let d;
	try { d = JSON.parse(t); } catch (e) { console.log('BADJSON:' + t.slice(0, 600)); process.exit(1); }
	let v;
	try { v = eval(process.argv[2]); } catch (e) { v = undefined; }
	console.log(v === undefined || v === null ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v));
});
