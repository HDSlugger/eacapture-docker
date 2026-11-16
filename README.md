# EACapture for Docker

An unofficial Docker wrapper for **EACapture**, SiliconDust's capture program for
ATSC 3.0 HDHomeRun tuners. It is not made or endorsed by SiliconDust. (Although they did reference it in their newsletter...)

## Why run this?

SiliconDust is evaluating how ATSC 3.0 channels, including encrypted ones, perform
during the **national Emergency Alert System (EAS) test**. To do that, they need
real over-the-air recordings from tuners in many homes.

EACapture does this automatically:

1. It finds your ATSC 3.0 HDHomeRun tuner on your network.
2. It checks in with SiliconDust's server (`eastest.silicondust.com`) every 8 hours
   to get a schedule of capture jobs.
3. At each scheduled time it records a short stretch from your tuner.
4. A little later it uploads that recording to SiliconDust, then deletes its local copy.

**For the recording to be useful, this must be running before, during and after the
test.** The test is scheduled for **November 17, 2026, around 2:20 p.m. Eastern**
(11:20 a.m. Pacific). Your computer must be on and awake, and the container
running, for that whole period.

The recordings are broadcast TV signals from your own tuner, and they are sent to
SiliconDust. Read their instructions and privacy information before taking part.

The image bundles `eacapture.php` with support for auto-discovery as well as specifying your HDHomeRun tuner's IP directly (`HDHR_IP`). Specifying an IP bypasses UDP broadcast discovery by querying the tuner over HTTP directly, making it easy to run across subnets/VLANs or in virtualized environments like Docker Desktop.

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
- **Mac or Windows with Docker Desktop:** Docker runs containers in a virtual
  machine, so local UDP broadcast discovery often fails ("Device discovery found
  no devices"). You can easily bypass discovery by specifying your tuner's IP with
  `HDHR_IP` in `compose.yaml`.

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

The first start downloads SiliconDust's script. Watch the log:

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

## Unraid installation (assuming Docker installed)

Thank you to u/v1605 at the SiliconDust forum for the following instructions:

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

## Common problems

| Log message | What to do |
|---|---|
| `Device discovery found no devices` | The container cannot see your tuner via UDP broadcast. Set `HDHR_IP` in `compose.yaml` to specify your tuner's IP directly across subnets or on Docker Desktop. |
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

## Stop and uninstall

Please stop it after the test ends (**November 17, 2026, 10 p.m. Eastern**).

    docker compose down        # stop and remove the container
    docker compose down -v     # also delete the data volume (state and any recordings)
    docker image rm ghcr.io/hdslugger/eacapture:latest

## What is stored

Everything lives in one Docker volume, `eacapture-data`:
`checkin_state.json` (your last check-in and the capture schedule), `eacapture.lock`,
and `Captures/` (recordings waiting to upload; each is deleted after a successful upload).

## Files in this project

- `Dockerfile`: a small PHP 8.4 image with the `sockets` extension, `curl`, and bundled `eacapture.php`.
- `entrypoint.sh`: image entrypoint that configures timezone and launches EACapture with optional `HDHR_IP`.
- `eacapture.php`: EACapture PHP script with ATSC 3.0 tuner discovery and HTTP direct IP support.
- `compose.yaml`: how to run it (host networking, volume, restart policy).
- `.github/workflows/publish.yml`: builds and publishes the image to GitHub's
  container registry for Intel and ARM.

## Disclaimer

This is an unofficial community wrapper provided as is. EACapture and HDHomeRun are
SiliconDust's. Check SiliconDust's own instructions for the test: https://forum.silicondust.com/forum/viewtopic.php?t=80838
