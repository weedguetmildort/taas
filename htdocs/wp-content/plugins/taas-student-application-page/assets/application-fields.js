/* Named HTML inputs -> REST details. No WordPress blocks, labels, classes or layout IDs. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  const PROGRAMS = { undergraduate: 1, masters: 2, phd: 3 };
  const EAP_STATUSES = { uf_taken: 1, currently_taking: 2, not_taken: 3, not_applicable: 4 };
  const DEFAULT_CALLING_CODE = '+1';
  const keyForValue = (mapping, value) => Object.keys(mapping).find(key => mapping[key] === value) || '';

  const COUNTRY_CODES = [
    'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS',
    'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE',
    'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM',
    'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC',
    'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA',
    'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW',
    'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO',
    'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW',
  ].join(' ').split(' ');

  // Calling codes and their regions from Google's libphonenumber metadata.
  // https://github.com/google/libphonenumber/blob/master/java/libphonenumber/src/com/google/i18n/phonenumbers/CountryCodeToRegionCodeMap.java
  const CALLING_CODE_REGIONS = [
    '1 US AG AI AS BB BM BS CA DM DO GD GU JM KN KY LC MP MS PR SX TC TT VC VG VI', '7 RU KZ',
    '20 EG', '27 ZA', '30 GR', '31 NL', '32 BE', '33 FR', '34 ES', '36 HU', '39 IT VA', '40 RO',
    '41 CH', '43 AT', '44 GB GG IM JE', '45 DK', '46 SE', '47 NO SJ', '48 PL', '49 DE', '51 PE',
    '52 MX', '53 CU', '54 AR', '55 BR', '56 CL', '57 CO', '58 VE', '60 MY', '61 AU CC CX', '62 ID',
    '63 PH', '64 NZ', '65 SG', '66 TH', '81 JP', '82 KR', '84 VN', '86 CN', '90 TR', '91 IN',
    '92 PK', '93 AF', '94 LK', '95 MM', '98 IR', '211 SS', '212 MA EH', '213 DZ', '216 TN', '218 LY',
    '220 GM', '221 SN', '222 MR', '223 ML', '224 GN', '225 CI', '226 BF', '227 NE', '228 TG',
    '229 BJ', '230 MU', '231 LR', '232 SL', '233 GH', '234 NG', '235 TD', '236 CF', '237 CM',
    '238 CV', '239 ST', '240 GQ', '241 GA', '242 CG', '243 CD', '244 AO', '245 GW', '246 IO',
    '247 AC', '248 SC', '249 SD', '250 RW', '251 ET', '252 SO', '253 DJ', '254 KE', '255 TZ',
    '256 UG', '257 BI', '258 MZ', '260 ZM', '261 MG', '262 RE YT', '263 ZW', '264 NA', '265 MW',
    '266 LS', '267 BW', '268 SZ', '269 KM', '290 SH TA', '291 ER', '297 AW', '298 FO', '299 GL',
    '350 GI', '351 PT', '352 LU', '353 IE', '354 IS', '355 AL', '356 MT', '357 CY', '358 FI AX',
    '359 BG', '370 LT', '371 LV', '372 EE', '373 MD', '374 AM', '375 BY', '376 AD', '377 MC',
    '378 SM', '380 UA', '381 RS', '382 ME', '383 XK', '385 HR', '386 SI', '387 BA', '389 MK',
    '420 CZ', '421 SK', '423 LI', '500 FK', '501 BZ', '502 GT', '503 SV', '504 HN', '505 NI',
    '506 CR', '507 PA', '508 PM', '509 HT', '590 GP BL MF', '591 BO', '592 GY', '593 EC', '594 GF',
    '595 PY', '596 MQ', '597 SR', '598 UY', '599 CW BQ', '670 TL', '672 NF', '673 BN', '674 NR',
    '675 PG', '676 TO', '677 SB', '678 VU', '679 FJ', '680 PW', '681 WF', '682 CK', '683 NU',
    '685 WS', '686 KI', '687 NC', '688 TV', '689 PF', '690 TK', '691 FM', '692 MH', '850 KP',
    '852 HK', '853 MO', '855 KH', '856 LA', '880 BD', '886 TW', '960 MV', '961 LB', '962 JO',
    '963 SY', '964 IQ', '965 KW', '966 SA', '967 YE', '968 OM', '970 PS', '971 AE', '972 IL',
    '973 BH', '974 QA', '975 BT', '976 MN', '977 NP', '992 TJ', '993 TM', '994 AZ', '995 GE',
    '996 KG', '998 UZ',
  ];

  function findInputs(form) {
    const required = [
      'ufEmail', 'countryOfOrigin', 'startSemester', 'collegeStatus', 'phone',
      'eap5836Status', 'eap5837Status', 'taHours', 'firstName', 'lastName', 'englishTestScore'
    ];
    const optional = ['ufid', 'additionalInfo', 'advisorName', 'resumeUrl', 'fwsEligibility', 'fwsAwardLetterUrl', 'phoneCallingCode'];
    const inputs = {};
    for (const name of [...required, ...optional]) {
      const matches = [...form.elements].filter(input => input.name === name);
      if (matches.length > 1 || (required.includes(name) && matches.length !== 1)) {
        throw new Error(`Missing or duplicated application input: ${name}`);
      }
      if (matches[0] && !matches[0].matches('input, select, textarea')) throw new Error(`Invalid input: ${name}`);
      inputs[name] = matches[0];
    }
    return inputs;
  }

  function createCountryLookup(countryInput) {
    const countryMap = new Map();
    const countryList = countryInput.ownerDocument.getElementById(countryInput.getAttribute('list'));
    const countryNames = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
    COUNTRY_CODES.forEach(code => {
      const name = countryNames ? countryNames.of(code) : code;
      countryMap.set(code.toLowerCase(), code);
      countryMap.set(name.toLowerCase(), code);
      countryList?.append(new Option(name, name));
    });

    return { countryMap, countryNames };
  }

  function populateCallingCodes(select, countryNames) {
    if (!select) return;
    for (const entry of CALLING_CODE_REGIONS) {
      const [code, ...regions] = entry.split(' ');
      const names = regions.map(region => countryNames?.of(region) || region);
      const label = code === '1' ? 'United States, Canada, and others'
        : names.length > 3 ? `${names.slice(0, 2).join(', ')}, and others` : names.join(', ');
      const option = new Option(`+${code} · ${label}`, `+${code}`);
      option.title = names.join(', ');
      select.append(option);
    }
  }

  function splitInternationalPhone(phone, select) {
    const compact = phone.replace(/[\s().-]/g, '');
    if (!/^\+[1-9]\d{1,14}$/.test(compact) || !select) return null;
    const callingCode = [...select.options].find(option => option.value && compact.startsWith(option.value))?.value;
    if (!callingCode || compact.length === callingCode.length) return null;
    return { callingCode, nationalNumber: compact.slice(callingCode.length) };
  }

  student.createApplicationFields = (form, { editing }) => {
    const inputs = findInputs(form);
    inputs.englishTestScore.required = true;
    const nameInputs = [inputs.firstName, inputs.lastName];
    const nameErrorInput = nameInputs[nameInputs.length - 1];
    // Optional controls can be omitted. Preserve their saved values when editing.
    const savedOptional = {};
    const setValue = (name, value) => {
      if (inputs[name]) inputs[name].value = value;
      else savedOptional[name] = value;
    };
    const { countryMap, countryNames } = createCountryLookup(inputs.countryOfOrigin);
    populateCallingCodes(inputs.phoneCallingCode, countryNames);
    const phoneCodeDisplay = form.querySelector('[data-phone-code-display]');
    const advisorSection = form.querySelector('[data-advisor-section]');
    const isPhd = () => inputs.collegeStatus.value === 'phd';

    function updatePhoneCodeDisplay() {
      if (phoneCodeDisplay) phoneCodeDisplay.textContent = inputs.phoneCallingCode?.value || '—';
    }

    function prefillAdmissionSemester(value) {
      const select = inputs.startSemester;
      if (select.tagName !== 'SELECT') {
        select.value = value || '';
        return;
      }
      const placeholder = select.querySelector('option[value=""]')?.textContent || 'Select semester';
      select.replaceChildren(new Option(placeholder, ''));
      const currentYear = new Date().getFullYear();
      for (let year = currentYear - 8; year <= currentYear + 1; year++) {
        for (const term of ['Spring', 'Summer', 'Fall']) {
          select.append(new Option(`${term} ${year}`, `${term} ${year}`));
        }
      }
      if (value && ![...select.options].some(option => option.value === value)) select.append(new Option(value, value));
      select.value = value || '';
    }

    function prefillExamResults(data, profile) {
      const value = Object.hasOwn(data, 'english_test_score') ? data.english_test_score : profile.english_test_score;
      setValue('englishTestScore', value ?? '');
    }

    function prefill(data) {
      form.reset();
      [...form.elements].forEach(input => input.setCustomValidity?.(''));
      const profile = data.profile || {};
      const advisorName = data.advisor_name ?? profile.advisor_name ?? '';
      const internationalPhone = splitInternationalPhone(profile.phone || '', inputs.phoneCallingCode);
      // Direct values share one assignment path, including omitted optional fields.
      const values = {
        ufid: data.identity.ufid,
        ufEmail: data.identity.email || '',
        phone: internationalPhone?.nationalNumber ?? (profile.phone || ''),
        taHours: profile.available_hours || '',
        resumeUrl: profile.resume_url || '',
        fwsEligibility: profile.enrollment_status === PROGRAMS.undergraduate ? profile.fws_eligibility || '' : '',
        fwsAwardLetterUrl: profile.fws_award_letter_url || '',
        additionalInfo: profile.additional_info || '',
        advisorName: advisorName === 'N/A' && data.can_edit !== false ? '' : advisorName
      };
      if (editing || typeof data.student_name === 'string') {
        // Only a full name is stored; students can correct this provisional split.
        const parts = (data.student_name || '').trim().split(/\s+/);
        values.firstName = parts.shift();
        values.lastName = parts.join(' ');
      }
      Object.entries(values).forEach(([name, value]) => setValue(name, value));
      if (inputs.phoneCallingCode) {
        // Keep legacy values that cannot be combined with a calling code editable.
        const phone = values.phone.trim();
        const canUseDefault = !phone || /^\d{1,14}$/.test(phone.replace(/[\s().-]/g, ''));
        inputs.phoneCallingCode.value = internationalPhone?.callingCode ?? (canUseDefault ? DEFAULT_CALLING_CODE : '');
        updatePhoneCodeDisplay();
      }

      inputs.countryOfOrigin.value = profile.country_code && countryNames ? countryNames.of(profile.country_code.toUpperCase()) : profile.country_code || '';
      inputs.collegeStatus.value = keyForValue(PROGRAMS, profile.enrollment_status);
      inputs.eap5836Status.value = keyForValue(EAP_STATUSES, profile.eap5836_status);
      inputs.eap5837Status.value = keyForValue(EAP_STATUSES, profile.eap5837_status);
      prefillAdmissionSemester(profile.admitted_semester);
      prefillExamResults(data, profile);
      updateProgramFields();
    }

    function updateFws() {
      const undergraduate = inputs.collegeStatus.value === 'undergraduate';
      const notRequired = ['no', 'not_applicable'].includes(inputs.fwsEligibility?.value);
      const section = form.querySelector('[data-fws-section]');
      if (section) section.hidden = !undergraduate;
      if (inputs.fwsEligibility) {
        inputs.fwsEligibility.disabled = !undergraduate;
        inputs.fwsEligibility.required = undergraduate;
      }
      if (inputs.fwsAwardLetterUrl) {
        inputs.fwsAwardLetterUrl.disabled = !undergraduate || notRequired;
        inputs.fwsAwardLetterUrl.toggleAttribute('data-fws-not-required', notRequired);
        inputs.fwsAwardLetterUrl.required = undergraduate && inputs.fwsEligibility?.value === 'yes';
        inputs.fwsAwardLetterUrl.setCustomValidity('');
      }
      const help = form.querySelector('#fws-award-letter-help');
      if (help) help.textContent = notRequired
        ? 'Not required. You do not need to provide an FWS award letter for No or N/A.'
        : 'Submit link to a PDF of the award letter uploaded in a file sharing site such as google drive, dropbox, etc';
    }

    function didSave() {
      if (inputs.collegeStatus.value !== 'undergraduate') {
        setValue('fwsEligibility', '');
        setValue('fwsAwardLetterUrl', '');
      }
      if (!isPhd()) setValue('advisorName', '');
    }

    function updateProgramFields() {
      updateFws();
      if (advisorSection) advisorSection.hidden = !isPhd();
      if (inputs.advisorName) inputs.advisorName.disabled = !isPhd();
    }

    function validateIdentity() {
      const country = countryMap.get(inputs.countryOfOrigin.value.trim().toLowerCase());
      inputs.countryOfOrigin.setCustomValidity(country ? '' : 'Select a country or enter its two-letter country code.');
      const name = nameInputs.map(input => input.value.trim()).join(' ');
      nameInputs.forEach(input => {
        input.setCustomValidity(input.value.trim() ? '' : 'Enter your name.');
      });
      if (name.length > 255) {
        nameErrorInput.setCustomValidity('Your full name must be no more than 255 characters.');
      }
      return { country, name };
    }

    function collectPhone() {
      const { phone, phoneCallingCode } = inputs;
      const value = phone.value.trim();
      const code = phoneCallingCode?.value;
      const international = splitInternationalPhone(value, phoneCallingCode);
      const digits = value.replace(/[\s().-]/g, '');
      phone.setCustomValidity('');
      if (!value) {
        phone.setCustomValidity('Enter your phone number.');
      } else if (code && international && international.callingCode !== code) {
        phone.setCustomValidity('The phone number does not match the selected calling code.');
      } else if (international) {
        return international.callingCode + international.nationalNumber;
      } else if (code) {
        if (/^\d+$/.test(digits) && code.length + digits.length <= 16) return code + digits;
        phone.setCustomValidity('Enter the number without the calling code, using up to 15 digits including the calling code.');
      } else if (value.length > 16) {
        phone.setCustomValidity('Enter a phone number of up to 16 characters, or select a calling code.');
      }
      return value;
    }

    function collect() {
      const { country, name } = validateIdentity();
      const phone = collectPhone();
      updateProgramFields();
      const awardLetter = inputs.fwsAwardLetterUrl;
      if (inputs.collegeStatus.value === 'undergraduate' && !awardLetter?.disabled && awardLetter?.value.trim()) {
        try {
          if (!['http:', 'https:'].includes(new URL(awardLetter.value.trim()).protocol)) throw new Error();
        } catch {
          awardLetter.setCustomValidity('Provide a valid HTTP or HTTPS FWS award letter URL.');
        }
      }
      if (!form.reportValidity()) return null;
      const values = new FormData(form);
      // FormData omits disabled controls; retain their displayed values too.
      const read = name => String(values.get(name) ?? inputs[name]?.value ?? savedOptional[name] ?? '').trim();
      const score = name => read(name) === '' ? null : Number(read(name));
      return {
        student_name: name,
        student_email: read('ufEmail'),
        profile: {
          phone,
          country_code: country,
          admitted_semester: read('startSemester'),
          available_hours: Number(read('taHours')),
          enrollment_status: PROGRAMS[read('collegeStatus')],
          eap5836_status: EAP_STATUSES[read('eap5836Status')],
          eap5837_status: EAP_STATUSES[read('eap5837Status')],
          resume_url: read('resumeUrl') || null,
          fws_eligibility: read('collegeStatus') === 'undergraduate' ? read('fwsEligibility') || null : 'no',
          fws_award_letter_url: read('collegeStatus') === 'undergraduate' && !['no', 'not_applicable'].includes(read('fwsEligibility')) ? read('fwsAwardLetterUrl') || null : null,
          english_test_score: score('englishTestScore'),
          additional_info: read('additionalInfo'),
          ...(isPhd() ? { advisor_name: read('advisorName') } : {})
        }
      };
    }

    inputs.countryOfOrigin.addEventListener('input', () => inputs.countryOfOrigin.setCustomValidity(''));
    inputs.phone.addEventListener('input', () => inputs.phone.setCustomValidity(''));
    inputs.phoneCallingCode?.addEventListener('change', () => {
      inputs.phone.setCustomValidity('');
      updatePhoneCodeDisplay();
    });
    nameInputs.forEach(input => {
      input.addEventListener('input', () => {
        input.setCustomValidity('');
        nameErrorInput.setCustomValidity('');
      });
    });
    inputs.collegeStatus.addEventListener('change', updateProgramFields);
    inputs.fwsEligibility?.addEventListener('change', updateFws);
    inputs.fwsAwardLetterUrl?.addEventListener('input', () => inputs.fwsAwardLetterUrl.setCustomValidity(''));
    return { prefill, collect, updateProgramFields, didSave, isPhd };
  };
})();
