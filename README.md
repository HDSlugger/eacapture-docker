# EACapture for Docker

An unofficial Docker wrapper for **EACapture**, Silicondust's capture program for
ATSC 3.0 HDHomeRun tuners. It is not made or endorsed by Silicondust.

## Why run this?

Silicondust is evaluating how ATSC 3.0 channels, including encrypted ones, perform
during the **national Emergency Alert System (EAS) test**. To do that, they need
real over-the-air recordings from tuners in many homes.

EACapture does this automatically:

1. It finds your ATSC 3.0 HDHomeRun tuner on your network.
2. It checks in with Silicondust's server (`eastest.silicondust.com`) every 8 hours
   to get a schedule of capture jobs.
3. At each scheduled time it records a short stretch from your tuner.
4. A little later it uploads that recording to Silicondust, then deletes its local copy.

**For the recording to be useful, this must be running before, during and after the
test.** The test is scheduled for **November 17, 2026, around 2:20 p.m. Eastern**
(11:20 a.m. Pacific). Your computer must be on and awake, and the container
running, for that whole period.

The recordings are broadcast TV signals from your own tuner, and they are sent to
Silicondust. Read their instructions and privacy information before taking part.

This repository does **not** contain Silicondust's script. The container downloads
it from Silicondust (`https://download.silicondust.com/EACapture/eacapture.php.txt`)
the first time it starts, prints its SHA-256 in the log, and runs it unmodified.

## What you need

- An **ATSC 3.0 HDHomeRun** (for example a FLEX 4K) on your home network, with
  **firmware 20261107 or newer**. Update it with the HDHomeRun app.
- A **fresh channel scan** on the tuner (HDHomeRun app: Settings, Channels). The
  tuner must report at least one ATSC 3.0 channel.
- A computer that stays on and connected to the same network as the tuner.
- **Docker** (see below).

### Which computer?

- **Linux, NAS or Raspberry Pi:** best choice. Host networking works, so the
  container can find the tuner.
- **Mac or Windows with Docker Desktop:** Docker runs containers in a hidden virtual
  machine, so the tuner may not be found. If the log says "Device discovery found
  no devices", this is why. Docker Desktop has an optional host-networking setting
  (Settings, Resources, Network) that may help, but it is not guaranteed.
  Silicondust's regular Windows program is a more reliable option on those machines.

## 1. Download

You need two things: Docker, and this project's `compose.yaml`.

**Install Docker**
- Mac or Windows: install Docker Desktop from https://www.docker.com/products/docker-desktop/
  and open it once so it starts.
- Linux: install Docker Engine and the Compose plugin
  (https://docs.docker.com/engine/install/).

**Get the project files** (either way):
- Click the green **Code** button on this page, choose **Download ZIP**, and unzip
  it, or
- If you use git:

      git clone https://github.com/HDSlugger/eacapture-docker.git

Open a terminal (Terminal on Mac, PowerShell on Windows) in the project folder.

## 2. Install

Open `compose.yaml` in any text editor and change the time zone to yours, for example:

    TZ: America/New_York

(Pacific `America/Los_Angeles`, Central `America/Chicago`, Mountain `America/Denver`.)
This only affects the times shown in the log.

Then download the image, or build it yourself:

    docker compose pull

To build from the source in this folder instead (slower, but you can inspect every
step):

    docker compose build

## 3. Activate and run

Start it in the background:

    docker compose up -d

The first start downloads Silicondust's script. Watch the log:

    docker compose logs -f

A healthy start looks like this:

    Downloading https://download.silicondust.com/EACapture/eacapture.php.txt
    Script SHA-256: <a long hex string>
    ... Found: HDHomeRun FLEX 4K 10XXXXXX
    ... Chosen: 10XXXXXX
    ... Check-in succeeded.
    ... Next capture to run at <date and time>

Press Ctrl+C to stop watching the log; the container keeps running.

"Check-in succeeded" means you are registered. After that the container records and
uploads on its own. It restarts automatically after reboots (`restart: unless-stopped`)
as long as Docker itself starts at login.

### Unraid installation

Thank you to u/v1605 at the Silicondust forum for the following instructions:

Now that EACapture has a Docker container, you can set it up by hand in Unraid. This was the best option for me. I don't have a Windows PC I can count on to be running during the test, but my NAS is always on. Here's how to set it up:

 Go to the Docker tab and click Add Container.
   
Fill in the following:
        
        Name: eacapture
        Repository: ghcr.io/hdslugger/eacapture:latest
        Network Type: Host
        
Add a Path:
        
        Container Path: /data
        Host Path: /mnt/user/appdata/eacapture
      
Add a Variable:
       
        Key: TZ
        Value: your time zone, e.g. America/New_York
    
Click Apply, then check the logs for "Check-in succeeded".

Set the container to autostart.

After November 17, 2026, 10 p.m. Eastern, disable or remove the container.

### Common problems

| Log message | What to do |
|---|---|
| `Device discovery found no devices` | The container cannot see your tuner. On Docker Desktop, see "Which computer?" above. Make sure the tuner and computer share a network. |
| `not running latest firmware` | Update the tuner in the HDHomeRun app. |
| `Please run a new channel scan` | Run a channel scan in the HDHomeRun app (Settings, Channels), then restart: `docker compose restart` |
| `No ATSC 3.0 channels detected` | The tuner needs a scan that finds ATSC 3.0 channels. |
| `Check-in failed ... Will retry` | Usually temporary (internet or firmware). It retries every 10 minutes. |

## Keep it running through the test

- Leave the computer on and **prevent it from sleeping** from at least a few hours
  before the test until a few hours after (Mac: System Settings, Battery or
  Energy; Windows: Power & sleep; or run `caffeinate -dimsu` on a Mac).
- Do not restart Docker or the container near a scheduled capture. A capture that is
  already underway when the program starts is skipped.
- Check the log once the day before to confirm "Next capture to run at ...".

## Update Silicondust's script

The script is cached in the data volume. To fetch the latest copy, uncomment this
line in `compose.yaml`:

    EACAPTURE_UPDATE_SCRIPT: "1"

and run `docker compose up -d`. Remember to comment it out again.

## Stop and uninstall

Please stop it after the test ends (**November 17, 2026, 10 p.m. Eastern**).

    docker compose down        # stop and remove the container
    docker compose down -v     # also delete the data volume (state and any recordings)
    docker image rm ghcr.io/hdslugger/eacapture:latest

## What is stored

Everything lives in one Docker volume, `eacapture-data`:
`eacapture.php` (the script), `checkin_state.json` (your last check-in and the
capture schedule), `eacapture.lock`, and `Captures/` (recordings waiting to upload;
each is deleted after a successful upload).

## Files in this project

- `Dockerfile`: a small PHP 8.4 image with the `sockets` extension and `curl`.
- `entrypoint.sh`: downloads Silicondust's script, prints its SHA-256, runs it.
- `compose.yaml`: how to run it (host networking, volume, restart policy).
- `.github/workflows/publish.yml`: builds and publishes the image to GitHub's
  container registry for Intel and ARM.

## Disclaimer

This is an unofficial community wrapper provided as is. EACapture and HDHomeRun are
Silicondust's. Check Silicondust's own instructions for the test.
